<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsolidatedWorkbookTest extends TestCase
{
    private ?string $schema = null;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useWeekdayCalendarFixture();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL integration test.');
        if (!str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('A dedicated test database is required.');
        $this->schema = 'audit_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        DB::table('users')->insert(['id'=>1,'username'=>'admin','full_name'=>'Audit Admin','password_hash'=>password_hash('audit-password', PASSWORD_BCRYPT),'role'=>'staff','active'=>true,'can_create'=>true,'can_edit'=>true,'can_import'=>true,'can_export'=>true,'can_delete'=>true,'can_delete_all'=>true,'can_resolve_alerts'=>true,'can_archive'=>true]);
        DB::statement("SELECT setval(pg_get_serial_sequence('users','id'),1,true)");
        $this->session = ['tracker_user'=>['id'=>1],'tracker_csrf'=>'audit-token','tracker_created_at'=>time(),'tracker_last_activity'=>time()];
    }

    protected function tearDown(): void
    {
        if ($this->schema) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    private function api(array $body, string $endpoint='data.php')
    {
        $response = $this->withSession($this->session)->withHeader('X-CSRF-Token','audit-token')->postJson('/api/'.$endpoint,$body); return $this->finishImportFixture($response,$body,$endpoint);
    }

    private function project(array $data=[]): array
    {
        $data += ['title'=>'Audit project','projectNo'=>'123','status'=>'Pending','updatedAt'=>'2026-10-05T00:00:00.000000Z'];
        $id=DB::table('projects')->insertGetId(['data'=>json_encode($data)]+TrackerDataController::projectColumns($data));
        return $data+['id'=>$id];
    }


    public function test_optional_bidders_and_source_details_save_without_changing_legacy_fields(): void {
        $before=$this->project(['approvedBudget'=>'1500','remarks'=>'Keep this note','customLegacy'=>'Keep metadata']);
        $record=$before+['losingBidder1'=>'Builder A','losingBidAmount1'=>'1,234.50','losingBidder2'=>'Builder B','losingBidAmount2'=>'0',
            'referenceNo'=>'12809708','taker'=>'Contact','owner'=>'Owner','approvedBudgetWords'=>'One thousand five hundred',
            'bidAmountWords'=>'One thousand','preProcurementDate'=>'2026-10-07','preBidDate'=>'2026-10-21',
            'liquidatedDamages'=>'No LD','completionSwaDate'=>'Source entry','releasedBy'=>'Officer','philgeps'=>'OK','physicalAccomplishment'=>'NYS'];
        $this->api(['action'=>'saveProject','record'=>$record])->assertOk();
        $saved=json_decode(DB::table('projects')->where('id',$before['id'])->value('data'),true);
        foreach ($record as $key=>$value) if (!in_array($key,['id','status','updatedAt','physicalAccomplishment'],true)) $this->assertSame($value,$saved[$key],$key);
        $this->assertSame('0',$saved['physicalAccomplishment']);
        $this->assertSame('Source entry',$saved['completionSwaDate']);
        $this->assertArrayNotHasKey('swa',$saved);
        $this->assertArrayNotHasKey('completion',$saved);
    }
    public function test_invalid_bid_amounts_are_rejected_without_writing(): void {
        $record=$this->project();
        foreach (['losingBidAmount1','losingBidAmount2'] as $key) foreach (['-1','1.234','10000000000000',[],true] as $value) {
            $this->api(['action'=>'saveProject','record'=>array_replace($record,[$key=>$value])])->assertUnprocessable()->assertJsonStructure(['errors'=>[$key]]);
        }
        $this->assertDatabaseCount('history',0);
    }

    public function test_losing_bid_statuses_survive_save_import_and_export(): void
    {
        $project = $this->project(['bidAmount' => '1250.00', 'losingBidAmount1' => '500.00']);
        $statuses = ['losingBidAmount1' => 'N/A', 'losingBidAmount2' => 'DQ'];
        $this->api(['action' => 'saveProject', 'record' => array_replace($project, $statuses)])->assertOk();
        $saved = json_decode(DB::table('projects')->where('id', $project['id'])->value('data'), true);
        $this->assertSame('N/A', $saved['losingBidAmount1']);
        $this->assertSame('DQ', $saved['losingBidAmount2']);
        $this->assertSame('1250.00', $saved['bidAmount']);

        $import = $this->project(['projectNo' => '124', 'title' => 'Imported bid statuses']);
        $this->api(['action' => 'bulkSaveProjects', 'batchId' => 'losing_bid_status_import', 'records' => [$import + $statuses]])->assertOk();
        $imported = json_decode(DB::table('projects')->where('id', $import['id'])->value('data'), true);
        $this->assertSame('N/A', $imported['losingBidAmount1']);
        $this->assertSame('DQ', $imported['losingBidAmount2']);

        $path = \App\Support\FormattedProjectExport::create([$saved], public_path('templates/project-export-template.xlsx'));
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($path) === true);
            $document = new \DOMDocument();
            $document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame('Bid Amount', $xpath->query('//x:c[@r="F1"]')->item(0)->textContent);
            $this->assertSame('1250.00', $xpath->evaluate('string(//x:c[@r="F2"]/x:v)'));
            $this->assertSame('N/A', $xpath->evaluate('string(//x:c[@r="BI2"]/x:is/x:t)'));
            $this->assertSame('DQ', $xpath->evaluate('string(//x:c[@r="BK2"]/x:is/x:t)'));
        } finally {
            @unlink($path);
        }
        $csv = $this->withSession($this->session)->get('/api/data.php?action=listProjects&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Bid Amount', $csv);
        $this->assertStringContainsString('N/A', $csv);
        $this->assertStringContainsString('DQ', $csv);
    }

    public function test_older_and_blank_new_import_columns_preserve_saved_bidders_and_attachments(): void {
        $record=$this->project(['losingBidder1'=>'Keep A','losingBidAmount1'=>'12.50','losingBidder2'=>'Keep B','losingBidAmount2'=>'0',
            'owner'=>'Keep owner','attachments'=>[['id'=>'kept','name'=>'notice.pdf','storedName'=>'notice.pdf']],'customLegacy'=>'preserved']);
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'consolidated_old_import','records'=>[
            ['id'=>$record['id'],'projectNo'=>$record['projectNo'],'title'=>$record['title'],'updatedAt'=>$record['updatedAt'],
            'losingBidder1'=>'','losingBidAmount1'=>'','owner'=>null,'referenceNo'=>'NEW REF']
        ]])->assertOk();
        $saved=json_decode(DB::table('projects')->where('id',$record['id'])->value('data'),true);
        foreach (['losingBidder1','losingBidAmount1','losingBidder2','losingBidAmount2','owner','attachments','customLegacy'] as $key) $this->assertSame($record[$key],$saved[$key],$key);
        $this->assertSame('NEW REF',$saved['referenceNo']);
        $this->assertDatabaseCount('projects',1);
    }
    public function test_formatted_workbook_and_csv_include_new_fields_as_typed_values(): void {
        $record=$this->project(['losingBidder1'=>'=unsafe formula','losingBidAmount1'=>'1,234.50','losingBidder2'=>'Builder B','losingBidAmount2'=>'0',
            'owner'=>'Owner','preBidDate'=>'2026-10-21','completionSwaDate'=>'2026-09-01']);
        $path=\App\Support\FormattedProjectExport::create([$record],public_path('templates/project-export-template.xlsx'));
        try {
            $zip=new \ZipArchive;$this->assertTrue($zip->open($path)===true);
            $doc=new \DOMDocument;$doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));$zip->close();
            $xp=new \DOMXPath($doc);$xp->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame(73,$xp->query('//x:row[@r="1"]/x:c')->length);
            $this->assertSame(73,$xp->query('//x:row[@r="2"]/x:c')->length);
            $this->assertSame('1234.50',$xp->evaluate('string(//x:c[@r="BI2"]/x:v)'));
            $this->assertSame('0',$xp->evaluate('string(//x:c[@r="BK2"]/x:v)'));
            $this->assertSame('=unsafe formula',$xp->evaluate('string(//x:c[@r="BH2"]/x:is/x:t)'));
            $this->assertSame(0,$xp->query('//x:f')->length);
            $this->assertSame('BU2',$xp->query('//x:row[@r="2"]/x:c')->item(72)->getAttribute('r'));
            if (getenv('CONSOLIDATED_QA_EXPORT')) copy($path,getenv('CONSOLIDATED_QA_EXPORT'));
        } finally { @unlink($path); }
        $csv=$this->withSession($this->session)->get('/api/data.php?action=listProjects&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Losing Bidder 1',$csv);
        $this->assertStringContainsString("'=unsafe formula",$csv);
        $this->assertStringContainsString('Physical Accomplishment',$csv);
    }
    public function test_local_attachment_uploaded_by_one_user_can_be_viewed_and_downloaded_by_another(): void {
        $record=$this->project(['losingBidder1'=>'Builder A','losingBidAmount1'=>'5.00']);
        $response=$this->withSession($this->session)->withHeader('X-CSRF-Token','audit-token')->post('/api/project-edit.php',[
            'projectId'=>$record['id'],'record'=>json_encode($record),'deleteIds'=>'[]',
            'files'=>[UploadedFile::fake()->createWithContent('notice.txt','Shared entirely over LAN')],
        ])->assertOk();
        $attachment=$response->json('record.attachments.0');
        $this->assertFileExists(config('tracker.attachments').'/'.$record['id'].'/'.$attachment['storedName']);
        $response->assertJsonPath('record.losingBidder1','Builder A');
        DB::table('users')->insert(['id'=>2,'username'=>'viewer','full_name'=>'Viewer','role'=>'staff','active'=>true,'password_hash'=>'fixture']);
        $viewer=['tracker_user'=>['id'=>2],'tracker_csrf'=>'view-token','tracker_created_at'=>time(),'tracker_last_activity'=>time()];
        $url='/api/attachments.php?projectId='.$record['id'].'&attachmentId='.$attachment['id'];
        $this->withSession($viewer)->get($url)->assertOk()->assertHeader('X-Content-Type-Options','nosniff')->assertHeader('Content-Disposition','inline; filename=notice.txt');
        $this->withSession($viewer)->get($url.'&download=1')->assertOk()->assertHeader('Content-Disposition','attachment; filename=notice.txt');
        $this->withSession($viewer)->withHeader('X-CSRF-Token','view-token')->post('/api/attachments.php',['projectId'=>$record['id'],'action'=>'upload','files'=>[UploadedFile::fake()->createWithContent('denied.txt','no')]])->assertForbidden();
        $this->withSession($viewer)->get($url.'&attachmentId=missing')->assertNotFound();
    }
}
