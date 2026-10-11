<?php
namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrityAuditTest extends TestCase
{
    private ?string $schema=null;
    private array $session;
    protected function setUp(): void
    {
        parent::setUp();
        if(DB::connection()->getDriverName()!=='pgsql') $this->markTestSkipped('PostgreSQL integration test.');
        if(!str_ends_with(DB::connection()->getDatabaseName(),'_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema='integrity_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        DB::table('users')->insert(['id'=>1,'username'=>'admin','full_name'=>'Test Admin','role'=>'admin','active'=>true,'password_hash'=>password_hash('test-password',PASSWORD_BCRYPT)]);
        $this->session=['tracker_user'=>['id'=>1],'tracker_csrf'=>'integrity-token','tracker_created_at'=>time(),'tracker_last_activity'=>time()];
    }
    protected function tearDown(): void
    {
        if($this->schema){DB::statement('SET search_path TO public');DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');}
        parent::tearDown();
    }
    private function api(array $body,string $endpoint='data.php')
    {
        return $this->withTrackerSession($this->session)->withHeader('X-CSRF-Token','integrity-token')->postJson('/api/'.$endpoint,$body);
    }
    private function project(): array
    {
        $data=['title'=>'Integrity project','projectNo'=>'1','status'=>'Pending','updatedAt'=>'2026-10-06T00:00:00.000000Z'];
        $id=DB::table('projects')->insertGetId(['data'=>json_encode($data)]+TrackerDataController::projectColumns($data));
        return $data+['id'=>$id];
    }
    public function test_year_zero_is_rejected_before_database_write(): void
    {
        $this->api(['action'=>'saveProject','isImport'=>true,'record'=>['title'=>'Invalid calendar','biddingDate'=>'0000-01-01']])->assertStatus(422);
        $this->api(['action'=>'saveProject','record'=>['title'=>'Invalid calendar','biddingDate'=>"2026-01\0-01",'approvedBudget'=>'1000','winningBidder'=>'Supplier']])->assertStatus(422);
        $this->assertSame(0,DB::table('projects')->count());
    }
    public function test_invalid_import_and_archive_envelopes_are_rejected(): void
    {
        $record=$this->project();
        $this->api(['action'=>'bulkSaveProjects','batchId'=>['invalid'],'records'=>[['title'=>'Import']]])->assertStatus(422);
        $this->api(['action'=>'setProjectArchived','id'=>$record['id'],'archived'=>'invalid'])->assertStatus(422);
        $this->api(['action'=>'setProjectArchived','id'=>$record['id']])->assertStatus(422);
        $this->assertNull(DB::table('projects')->value('archived_at'));
    }
    public function test_history_nested_values_and_orphans_are_rejected(): void
    {
        $this->api(['action'=>'saveHistory','record'=>['projectId'=>['invalid'],'timestamp'=>['invalid']]])->assertStatus(422);
        $this->api(['action'=>'saveHistory','record'=>['projectId'=>999,'timestamp'=>'2026-10-06T00:00:00Z']])->assertStatus(422);
        $this->assertSame(0,DB::table('history')->count());
        $record=$this->project();
        $this->api(['action'=>'saveHistory','record'=>['projectId'=>$record['id'],'timestamp'=>'2026-10-06T00:00:00Z','action'=>'Resolved','details'=>'Test entry']])->assertOk();
        $this->assertSame(1,DB::table('history')->count());
    }
    public function test_nested_attachment_delete_ids_are_rejected(): void
    {
        $record=$this->project();
        $this->api(['projectId'=>$record['id'],'record'=>json_encode($record),'deleteIds'=>json_encode([['invalid']])],'project-edit.php')->assertStatus(422);
        $this->assertSame(0,DB::table('history')->count());
    }
}
