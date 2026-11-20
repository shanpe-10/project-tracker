<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FocusedAuditTest extends TestCase
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

    public function test_account_and_audit_timestamps_include_the_utc_offset(): void
    {
        DB::table('users')->where('id',1)->update(['role'=>'admin','created_at'=>'2026-10-05 00:00:00']);
        $this->api(['action'=>'listManagedUsers'],'auth.php')->assertOk()->assertJsonPath('users.0.createdAt','2026-10-05T00:00:00+00:00');
        DB::table('users')->where('id',1)->update(['role'=>'admin']);
        DB::table('audit_log')->insert(['actor_id'=>1,'actor_username'=>'admin','action'=>'demo','entity_type'=>'project','summary'=>'Demo','created_at'=>'2026-10-05 00:00:00']);
        $this->api(['action'=>'listAuditLog'],'auth.php')->assertOk()->assertJsonPath('entries.0.createdAt','2026-10-05T00:00:00+00:00');
    }

    public function test_failed_audit_writes_roll_back_crud_and_import(): void
    {
        $record=$this->project();
        DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_force_failure CHECK (action NOT IN ('project.saved','project.deleted','project.bulk_imported','database.cleared'))");
        $this->api(['action'=>'saveProject','record'=>array_replace($record,['title'=>'Uncommitted'])])->assertStatus(500);
        $this->assertSame('Audit project',json_decode(DB::table('projects')->value('data'),true)['title']);
        DB::table('users')->where('id',1)->update(['role'=>'admin']);
        $this->confirmDirectFixture(['targetType'=>'project','id'=>$record['id']], 'audit-password')->assertStatus(500);
        $this->assertSame(1,DB::table('projects')->count());
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'audit_failed_event','records'=>[['title'=>'Uncommitted import']]])->assertStatus(500);
        $this->assertSame(1,DB::table('projects')->count());
        $this->assertSame(0,DB::table('import_jobs')->where('status','completed')->count());
        $this->assertSame(0,DB::table('import_job_changes')->count());
        $this->confirmDirectFixture(['targetType'=>'project','all'=>true], 'audit-password')->assertStatus(500);
        $this->assertSame(1,DB::table('projects')->count());
    }

    public function test_alert_exports_match_the_alert_list(): void
    {
        $this->project(['title'=>'Active alert project','projectNo'=>'1','biddingDate'=>'2020-01-01']);
        $this->project(['title'=>'Unrelated project','projectNo'=>'2']);
        $this->withSession($this->session)->getJson('/api/data.php?action=listProjects&lifecycle=alerts')->assertOk()->assertJsonPath('totalRecords',1);
        $csv=$this->withSession($this->session)->get('/api/data.php?action=listProjects&lifecycle=alerts&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Active alert project',$csv);
        $this->assertStringNotContainsString('Unrelated project',$csv);
        $csv=$this->withSession($this->session)->get('/api/data.php?action=listProjects&lifecycle=alerts&search=missing&format=csv')->assertOk()->streamedContent();
        $this->assertSame(1,substr_count(trim($csv),"\n")+1);
    }

    public function test_csv_export_treats_formulas_as_text(): void
    {
        $this->project(['title'=>'=1+1','winningBidder'=>"\t=2+2",'remarks'=>'+SUM(1,2)']);
        $csv=$this->withSession($this->session)->get('/api/data.php?action=listProjects&format=csv')->assertOk()->streamedContent();
        $lines=explode("\n",trim($csv));
        $row=str_getcsv($lines[1],',','"','');
        $row=array_combine(array_keys(\App\Support\TrackerProjectFields::columns()),$row);
        $this->assertSame("'=1+1",$row['title']);
        $this->assertSame("'\t=2+2",$row['winningBidder']);
        $this->assertSame("'+SUM(1,2)",$row['remarks']);
    }

    public function test_public_account_requests_are_disabled(): void
    {
        foreach (['register', 'registrationStatus'] as $action) {
            $this->postJson('/api/auth.php', ['action'=>$action, 'username'=>'audit.staff', 'fullName'=>'Audit Staff', 'password'=>'valid-test-password'])
                ->assertForbidden()->assertJsonPath('ok', false)->assertJsonPath('message', 'Contact Admin for a new account.');
        }
        $this->assertSame(1, DB::table('users')->count());
        $this->assertNull(session('registration_submission'));
        DB::table('users')->where('id', 1)->update(['role'=>'admin']);
        $this->api(['action'=>'register', 'username'=>'audit.staff', 'fullName'=>'Audit Staff', 'password'=>'valid-test-password'], 'auth.php')
            ->assertForbidden()->assertJsonPath('message', 'Contact Admin for a new account.');
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_it_account_creation_enforces_bcrypt_password_limits(): void
    {
        DB::table('users')->where('id', 1)->update(['role'=>'admin']);
        $body = ['action'=>'createUser', 'username'=>'audit.staff', 'fullName'=>'Audit Staff', 'role'=>'staff', 'active'=>true];
        foreach ([str_repeat('a', 73), str_repeat('界', 25), "valid-password\0suffix"] as $password) {
            $this->api($body + ['password'=>$password], 'auth.php')->assertStatus(422);
        }
        $this->assertSame(1, DB::table('users')->count());
        $this->api($body + ['password'=>str_repeat('a', 72)], 'auth.php')->assertOk();
        $this->assertSame(2, DB::table('users')->count());
        $this->assertTrue((bool) DB::table('users')->where('username', 'audit.staff')->value('active'));
    }

    public function test_project_creation_cannot_forge_media_or_archive_state(): void
    {
        $record=['title'=>'Created','biddingDate'=>'2026-10-05','approvedBudget'=>'100','winningBidder'=>'Demo builder','attachments'=>[['id'=>'fake','storedName'=>'fake.txt']],'archivedAt'=>'2020-01-01','archivedBy'=>'other'];
        $saved=$this->api(['action'=>'saveProject','record'=>$record])->assertOk()->json('record');
        $this->assertEmpty($saved['attachments']??[]);
        $this->assertArrayNotHasKey('archivedAt',$saved);
        $this->assertArrayNotHasKey('archivedBy',$saved);
    }

    public function test_import_preserves_server_media_and_rejects_stale_overwrites(): void
    {
        $record=$this->project(['attachments'=>[['id'=>'keep','storedName'=>'keep.txt']]]);
        $incoming=$record;
        $incoming['title']='Imported title';
        $incoming['attachments']=[];
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'audit_overwrite_01','records'=>[$incoming]])->assertOk();
        $saved=json_decode(DB::table('projects')->value('data'),true);
        $this->assertSame($record['attachments'],$saved['attachments']);
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'audit_overwrite_02','records'=>[$incoming]])->assertStatus(422);
        $this->assertSame($saved,json_decode(DB::table('projects')->value('data'),true));
        $this->api(['action'=>'rollbackImport','batchId'=>'audit_overwrite_01'])->assertOk();
        $this->assertSame('Audit project',json_decode(DB::table('projects')->value('data'),true)['title']);
    }

    public function test_import_creation_cannot_forge_media(): void
    {
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'audit_new_media_01','records'=>[['title'=>'Import','attachments'=>[['id'=>'fake','storedName'=>'fake.txt']]]]])->assertOk();
        $this->assertEmpty(json_decode(DB::table('projects')->value('data'),true)['attachments']??[]);
    }

    public function test_malformed_request_types_are_validation_errors(): void
    {
        $record=$this->project();
        $this->withSession($this->session)->getJson('/api/data.php?action=listProjects&search[]=invalid')->assertStatus(422);
        $this->withSession($this->session)->getJson('/api/profile.php?userId[]=invalid')->assertStatus(422);
        $this->api(['action'=>'deleteProject','id'=>1.5])->assertStatus(422);
        $this->api(['action'=>'setProjectArchived','id'=>[$record['id']],'archived'=>true])->assertStatus(422);
        $this->api(['action'=>'saveTimelineSettings','settings'=>'invalid'])->assertStatus(422);
        $this->api(['action'=>'saveProject','record'=>array_replace($record,['remarks'=>['bad']])])->assertStatus(422);
        $this->api(['action'=>'saveProject','record'=>array_replace($record,['timelineAlertsCompleted'=>[['bad']]])])->assertStatus(422);
        DB::table('users')->where('id',1)->update(['role'=>'admin']);
        DB::table('users')->where('id',1)->update(['role'=>'admin']);
        $this->api(['destination'=>['bad'],'enabled'=>true],'backups.php')->assertStatus(422);
        $this->api(['action'=>'unexpected','destination'=>'D:/audit-unexpected'],'backups.php')->assertStatus(400);
        $this->api(['fullName'=>['bad']],'profile.php')->assertStatus(422);
        $this->assertSame(1,DB::table('projects')->count());
    }

    public function test_new_invalid_milestones_are_rejected_but_legacy_values_survive(): void
    {
        $record=$this->project(['noa'=>'legacy-undated']);
        $this->api(['action'=>'saveProject','record'=>array_replace($record,['title'=>'Changed'])])->assertOk();
        $saved=json_decode(DB::table('projects')->value('data'),true)+['id'=>$record['id']];
        $this->api(['action'=>'saveProject','record'=>array_replace($saved,['noa'=>'2026-02-30'])])->assertStatus(422);
        $this->withSession($this->session)->withHeader('X-CSRF-Token','audit-token')->post('/api/project-edit.php',['projectId'=>$record['id'],'record'=>json_encode(array_replace($saved,['ntpDate'=>'2026-02-30']))])->assertStatus(422);
        $this->assertSame('legacy-undated',json_decode(DB::table('projects')->value('data'),true)['noa']);
    }

    public function test_nested_uploads_are_rejected_cleanly(): void
    {
        $record=$this->project();
        $this->withSession($this->session)->withHeader('X-CSRF-Token','audit-token')->post('/api/project-edit.php',['projectId'=>$record['id'],'record'=>json_encode($record),'files'=>[['nested'=>UploadedFile::fake()->createWithContent('demo.txt','demo')]]])->assertStatus(422);
        $this->withSession($this->session)->withHeader('X-CSRF-Token','audit-token')->post('/api/profile.php',['fullName'=>'Admin','photo'=>[UploadedFile::fake()->createWithContent('demo.png','demo')]])->assertStatus(422);
    }

    public function test_alert_updates_and_history_are_atomic_and_permission_checked(): void
    {
        $record=$this->project(['timelineAlertsCompleted'=>['noa-overdue','ntp-overdue'],'timelineMarkedDoneDate'=>'2026-10-05T00:00:00Z']);
        $changed=array_replace($record,['timelineAlertsCompleted'=>['ntp-overdue']]);
        DB::statement("ALTER TABLE history ADD CONSTRAINT audit_fail_history CHECK (data->>'action' <> 'FAIL')");
        $this->api(['action'=>'saveTimelineRecord','record'=>$changed,'history'=>['action'=>'FAIL','details'=>'Demo']])->assertStatus(500);
        $this->assertSame($record['updatedAt'],json_decode(DB::table('projects')->value('data'),true)['updatedAt']);
        $this->assertSame(0,DB::table('audit_log')->count());
        $this->assertSame(0,DB::table('history')->count());
        $saved=$this->api(['action'=>'saveTimelineRecord','record'=>$changed,'history'=>['action'=>'Restore historical NOA','details'=>'Demo']])->assertOk()->json('record');
        $this->assertSame(['ntp-overdue'],$saved['timelineAlertsCompleted']);
        $this->assertSame(1,DB::table('history')->count());
        DB::table('users')->insert(['id'=>2,'username'=>'resolver','full_name'=>'Resolver','password_hash'=>'hash','role'=>'staff','active'=>true,'can_resolve_alerts'=>true]);
        $this->session['tracker_user']=['id'=>2];
        $restored=array_replace($saved,['timelineAlertsCompleted'=>[],'timelineCompletedMarked'=>false,'timelineUnmarkedType'=>'noa-overdue','timelineUnmarkedAt'=>'2026-10-05T00:00:00Z']);
        $this->api(['action'=>'saveTimelineRecord','record'=>$restored,'history'=>['action'=>'Restore alert']])->assertOk();
        $restored=json_decode(DB::table('projects')->value('data'),true)+['id'=>$record['id']];
        $this->api(['action'=>'saveTimelineRecord','record'=>array_replace($restored,['title'=>'Not allowed']),'history'=>['action'=>'Forged']])->assertForbidden();
        DB::table('users')->where('id',2)->update(['can_resolve_alerts'=>false]);
        $this->api(['action'=>'saveTimelineRecord','record'=>$restored,'history'=>['action'=>'Denied']])->assertForbidden();
    }

    public function test_bidding_deadlines_clamp_calendar_month_ends(): void
    {
        foreach (['2026-01-31'=>'2026-03-02','2024-01-31'=>'2024-02-29','2026-12-31'=>'2027-02-01','2026-10-05'=>'2026-11-05'] as $start=>$expected) {
            $dates=\App\Support\TrackerMilestones::dueDates(['biddingDate'=>$start]);
            foreach (['preInspectionDueDate','ntpDueDate','materialTestingDueDate'] as $field) $this->assertSame($expected,$dates[$field]);
        }
        $this->assertNull(\App\Support\TrackerMilestones::dueDates(['biddingDate'=>'2026-02-30'])['ntpDueDate']);
    }

    public function test_final_inspection_uses_extension_end_or_calendar_duration(): void
    {
        $dates=fn ($r)=>\App\Support\TrackerMilestones::dueDates($r)['finalInspectionDueDate'];
        $this->assertSame('2026-11-02',$dates(['ldNewCompletionDate'=>'2026-12-01','contractEndDate'=>'2026-11-01','ntpDate'=>'2026-10-01','contractDuration'=>'60CD']));
        $this->assertSame('2026-11-02',$dates(['contractEndDate'=>'2026-11-01','ntpDate'=>'2026-10-01','contractDuration'=>'60CD']));
        $this->assertSame('2026-12-24',$dates(['contractRef'=>'2026-10-01','ntpDate'=>'2026-10-20','contractDuration'=>60]));
        $this->assertSame('2026-12-24',$dates(['contractRef'=>'2026-10-01','contractDuration'=>60.5]));
        foreach (['60 working days','-1','0','3.5','999999999999999'] as $value) $this->assertNull($dates(['ntpDate'=>'2026-10-01','contractDuration'=>$value]));
        $this->assertNull($dates(['contractDuration'=>'60CD']));
    }

    public function test_final_and_bidding_alert_boundaries_and_resolution(): void
    {
        $project=['status'=>'Pending','biddingDate'=>'2026-01-31','contractEndDate'=>'2026-03-20'];
        $settings=['ntp'=>7,'material'=>15,'finalInspection'=>7];
        $alerts=fn ($day,$r=[])=>\App\Support\TrackerMilestones::alerts(array_replace($project,$r),$settings,new \DateTimeImmutable($day));
        $types=fn ($list)=>array_column($list,'type');
        $this->assertNotContains('final-inspection',$types($alerts('2026-03-10')));
        $this->assertContains('final-inspection',$types($alerts('2026-03-11')));
        $this->assertContains('final-inspection',$types($alerts('2026-03-20')));
        $this->assertContains('final-inspection-overdue',$types($alerts('2026-03-21')));
        $this->assertNotContains('final-inspection-overdue',$types($alerts('2026-03-21',['timelineAlertsCompleted'=>['final-inspection']])));
        $this->assertNotContains('final-inspection',$types($alerts('2026-03-13',['finalInspection'=>'2026-03-12'])));
        $this->assertContains('final-inspection',$types($alerts('2026-03-13',['finalInspection'=>'2026-03-15'])));
        foreach ([['status'=>'Completed'],['status'=>'Released'],['archivedAt'=>'2026-03-01'],['timelineCompletedMarked'=>true]] as $r) $this->assertSame([],$alerts('2026-03-13',$r));
        $due=$alerts('2026-02-28');
        $this->assertContains('pre-inspection',$types($due));
        $this->assertContains('ntp-due',$types($due));
        $this->assertContains('material-test',$types($due));
        foreach ($due as $item) $this->assertSame('2026-03-02',$item['dueDate']);
        $this->assertContains('material-overdue',$types($alerts('2026-03-03',[''=>'2026-02-25'])));
        $overdue=$types($alerts('2026-03-03'));
        foreach (['pre-inspection-overdue','ntp-due-overdue','material-overdue'] as $type) $this->assertContains($type,$overdue);
        $this->assertNotContains('ntp-due-overdue',$types($alerts('2026-03-01',['ntpDate'=>'2026-02-25'])));
        $this->assertNotContains('pre-inspection-overdue',$types($alerts('2026-03-01',['preInspection'=>'2026-02-25'])));
    }

    public function test_final_inspection_settings_are_validated_and_legacy_clients_preserve_them(): void
    {
        DB::table('users')->where('id',1)->update(['role'=>'admin']);
        $settings=['noa'=>10,'ntp'=>30,'material'=>15,'completion'=>15,'finalInspection'=>23];
        $this->api(['action'=>'saveTimelineSettings','settings'=>$settings])->assertOk()->assertJsonPath('settings.finalInspection',23);
        unset($settings['finalInspection']);
        $this->api(['action'=>'saveTimelineSettings','settings'=>$settings])->assertOk()->assertJsonPath('settings.finalInspection',23);
        foreach ([0,3651,'seven',null,[],1.5] as $invalid) $this->api(['action'=>'saveTimelineSettings','settings'=>$settings+['finalInspection'=>$invalid]])->assertStatus(422);
        $this->api(['action'=>'saveTimelineSettings','settings'=>'invalid'])->assertStatus(422);
        $this->assertSame(23,json_decode(DB::table('application_settings')->value('setting_value'),true)['finalInspection']);
    }

    public function test_new_deadlines_work_in_dashboard_alert_list_and_exports(): void
    {
        $today=new \DateTimeImmutable('today',new \DateTimeZone('Asia/Taipei'));
        $end=$today->modify('+7 days')->format('Y-m-d');
        $record=$this->project(['title'=>'Final deadline demo','contractEndDate'=>$end]);
        $this->project(['title'=>'No deadlines','projectNo'=>'124']);
        $dashboard=$this->withSession($this->session)->getJson('/api/data.php?action=dashboard')->assertOk()->json('alerts');
        $this->assertSame(['final-inspection'],array_column($dashboard,'type'));
        $this->assertSame($end,$dashboard[0]['dueDate']);
        $this->withSession($this->session)->getJson('/api/data.php?action=listProjects&lifecycle=alerts')->assertOk()->assertJsonPath('totalRecords',1);
        $csv=$this->withSession($this->session)->get('/api/data.php?action=listProjects&lifecycle=alerts&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Final deadline demo',$csv);
        $this->assertStringNotContainsString('No deadlines',$csv);
        $record['preInspection']=$today->format('Y-m-d');
        $record['finalInspection']=$today->format('Y-m-d');
        $this->api(['action'=>'saveProject','record'=>$record])->assertOk();
        $this->withSession($this->session)->getJson('/api/data.php?action=dashboard')->assertOk()->assertJsonPath('alertTotal',0);
    }


    public function test_material_testing_date_crud_validation_and_reopening_alert(): void
    {
        $today = now(config('app.timezone'))->format('Y-m-d');
        $record = $this->project(['biddingDate'=>'2020-01-01','preInspection'=>$today,'ntpDate'=>$today,'finalInspection'=>$today,'contractEndDate'=>$today]);
        $getTypes = fn () => array_column($this->withSession($this->session)->getJson('/api/data.php?action=dashboard')->assertOk()->json('alerts'), 'type');
        $this->assertSame(['material-overdue'], $getTypes());
        $record = $this->api(['action'=>'saveProject','record'=>array_replace($record,['materialTestingDate'=>$today])])->assertOk()->json('record');
        $this->assertSame([], $getTypes());
        $this->assertEmpty($record['timelineAlertsCompleted'] ?? []);
        foreach (['2026-02-30','invalid',['invalid']] as $invalid) {
            $this->api(['action'=>'saveProject','record'=>array_replace($record,['materialTestingDate'=>$invalid])])->assertStatus(422);
        }
        $futureDate = now(config('app.timezone'))->addDay()->toDateTimeImmutable();
        while (!\App\Support\TrackerMilestones::isWorkday($futureDate)) $futureDate=$futureDate->modify('+1 day');
        $future = $futureDate->format('Y-m-d');
        $record = $this->api(['action'=>'saveProject','record'=>array_replace($record,['materialTestingDate'=>$future])])->assertOk()->json('record');
        $this->assertSame(['material-overdue'], $getTypes());
        $record = $this->api(['action'=>'saveProject','record'=>array_replace($record,['materialTestingDate'=>''])])->assertOk()->json('record');
        $this->assertSame(['material-overdue'], $getTypes());
        $this->api(['action'=>'saveTimelineRecord','record'=>array_replace($record,['timelineAlertsCompleted'=>['material-overdue']]),'history'=>['action'=>'Resolve demo']])->assertStatus(422);
        // Seed a historical manual marker; new manual date-based resolutions are no longer permitted.
        $historical=$record; unset($historical['id']); $historical['timelineAlertsCompleted']=['material-overdue'];
        DB::table('projects')->where('id',$record['id'])->update(['data'=>json_encode($historical)]);
        $record=$historical+['id'=>$record['id']];
        $record = $this->api(['action'=>'saveProject','record'=>array_replace($record,['materialTestingDate'=>$today])])->assertOk()->json('record');
        $record = $this->api(['action'=>'saveProject','record'=>array_replace($record,['materialTestingDate'=>''])])->assertOk()->json('record');
        $this->assertSame(['material-overdue'], $record['timelineAlertsCompleted']);
        $this->assertSame([], $getTypes());
    }

    public function test_combined_reminder_settings_preserve_legacy_values_and_clients(): void
    {
        DB::table('users')->where('id',1)->update(['role'=>'admin']);
        DB::table('application_settings')->insert(['setting_key'=>'timeline_days','setting_value'=>json_encode(['noa'=>10,'ntp'=>30,'material'=>7,'completion'=>15,'finalInspection'=>23])]);
        $this->withSession($this->session)->getJson('/api/data.php')->assertOk()->assertJsonPath('timelineSettings.bidding',7);
        $settings=['noa'=>10,'ntp'=>30,'material'=>7,'completion'=>15,'finalInspection'=>23,'bidding'=>19];
        $this->api(['action'=>'saveTimelineSettings','settings'=>$settings])->assertOk()->assertJsonPath('settings.bidding',19)->assertJsonPath('settings.ntp',30)->assertJsonPath('settings.material',7);
        unset($settings['bidding']);
        $this->api(['action'=>'saveTimelineSettings','settings'=>$settings])->assertOk()->assertJsonPath('settings.bidding',19);
        foreach ([0,3651,'invalid',null,[]] as $invalid) $this->api(['action'=>'saveTimelineSettings','settings'=>$settings+['bidding'=>$invalid]])->assertStatus(422);
        $project=['biddingDate'=>'2026-01-31'];
        $settings['bidding']=7;
        $this->assertSame([],\App\Support\TrackerMilestones::alerts($project,$settings,new \DateTimeImmutable('2026-02-18')));
        $this->assertCount(3,\App\Support\TrackerMilestones::alerts($project,$settings,new \DateTimeImmutable('2026-02-19')));
    }

    public function test_material_date_import_export_and_old_spreadsheets_preserve_the_date(): void
    {
        $record=$this->project(['materialTestingDate'=>'2026-09-30']);
        $csv=$this->withSession($this->session)->get('/api/data.php?action=listProjects&format=csv')->assertOk()->streamedContent();
        $rows=array_map(fn ($line)=>str_getcsv($line,',','"',''),explode("\n",trim($csv)));
        $materialHeader=\App\Support\TrackerProjectFields::columns()['materialTestingDate'];
        $materialColumn=array_search($materialHeader,$rows[0],true);
        $this->assertNotFalse($materialColumn);
        $this->assertSame('2026-09-30',$rows[1][$materialColumn]);
        $xlsx=\App\Support\FormattedProjectExport::create([$record],public_path('templates/project-export-template.xlsx'));
        try {
            $zip=new \ZipArchive(); $zip->open($xlsx); $xml=$zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
            $document=new \DOMDocument(); $document->loadXML($xml); $xpath=new \DOMXPath($document); $xpath->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame('BU2',$xpath->query('//x:row[@r="2"]/x:c')->item(72)->getAttribute('r'));
            $headerCell=$xpath->query('//x:row[@r="1"]/x:c')->item($materialColumn);
            $this->assertSame($materialHeader,$headerCell->textContent);
            $reference=preg_replace('/1$/','2',$headerCell->getAttribute('r'));
            $cell=$xpath->query('//x:c[@r="'.$reference.'"]')->item(0);
            $this->assertSame((new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable('2026-09-30'))->format('%a'),$cell->textContent);
            $this->assertSame('n',$cell->getAttribute('t'));
        } finally { @unlink($xlsx); }
        $oldSpreadsheet=$record; unset($oldSpreadsheet['materialTestingDate']); $oldSpreadsheet['title']='Old spreadsheet update';
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'material_old_sheet','records'=>[$oldSpreadsheet]])->assertOk();
        $record=json_decode(DB::table('projects')->value('data'),true)+['id'=>$record['id']];
        $this->assertSame('2026-09-30',$record['materialTestingDate']);
        $record['materialTestingDate']='2026-02-30';
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'material_bad_sheet','records'=>[$record]])->assertStatus(422);
        $record['materialTestingDate']='2026-10-01';
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'material_new_sheet','records'=>[$record]])->assertOk();
        $this->assertSame('2026-10-01',json_decode(DB::table('projects')->value('data'),true)['materialTestingDate']);
    }

    public function test_legacy_material_and_swa_dates_resolve_without_marking_flags(): void
    {
        $today=now(config('app.timezone'))->format('Y-m-d');
        $record=$this->project(['ntpDate'=>'2020-01-01','materialTestingDate'=>$today]);
        $types=array_column($this->withSession($this->session)->getJson('/api/data.php?action=dashboard')->assertOk()->json('alerts'),'type');
        $this->assertSame([],$types);
        $this->api(['action'=>'saveProject','record'=>array_replace($record,['completion'=>'2020-01-01','swa'=>now(config('app.timezone'))->addDay()->format('Y-m-d')])])->assertStatus(422);
        $record=$this->api(['action'=>'saveProject','record'=>array_replace($record,['preInspection'=>'2020-01-01','finalInspection'=>'2020-01-01','completion'=>'2020-01-01','swa'=>''])])->assertOk()->json('record');
        $this->withSession($this->session)->getJson('/api/data.php?action=dashboard')->assertOk()->assertJsonPath('alerts.0.type','award-overdue');
        $this->api(['action'=>'saveProject','record'=>array_replace($record,['swa'=>$today])])->assertOk();
        $this->withSession($this->session)->getJson('/api/data.php?action=dashboard')->assertOk()->assertJsonPath('alertTotal',0);
        $automatic=\App\Support\TrackerMilestones::automaticResolutions(['biddingDate'=>'2020-01-01','ntpDate'=>$today,'preInspection'=>$today,'materialTestingDate'=>$today,'finalInspection'=>$today,'swa'=>$today],new \DateTimeImmutable($today));
        $this->assertCount(5,$automatic);
        $this->assertContains('ntp-overdue',$automatic[1]['aliases']);
        $this->assertSame([],\App\Support\TrackerMilestones::automaticResolutions(['materialTestingDate'=>'2026-02-30'],new \DateTimeImmutable($today)));
    }


    public function test_date_based_manual_resolutions_are_rejected_atomically(): void
    {
        $record=$this->project(['biddingDate'=>'2020-01-01','contractEndDate'=>'2020-02-01']);
        foreach (['pre-inspection','pre-inspection-overdue','ntp-due','ntp-due-overdue','material-test','material-overdue','final-inspection','final-inspection-overdue','award-close','award-overdue','ntp-award','ntp-overdue'] as $type) {
            $changed=array_replace($record,['timelineAlertsCompleted'=>[$type]]);
            $this->api(['action'=>'saveTimelineRecord','record'=>$changed,'history'=>['action'=>'Rejected date bypass']])->assertStatus(422);
            $this->api(['action'=>'saveProject','record'=>$changed])->assertStatus(422);
        }
        $this->api(['action'=>'saveTimelineRecord','record'=>array_replace($record,['timelineCompletedMarked'=>true]),'history'=>['action'=>'Rejected global bypass']])->assertStatus(422);
        $this->withSession($this->session)->withHeader('X-CSRF-Token','audit-token')->post('/api/project-edit.php',['projectId'=>$record['id'],'record'=>json_encode(array_replace($record,['timelineAlertsCompleted'=>['material-overdue']]))])->assertStatus(422);
        $this->assertSame(0,DB::table('history')->count());
        $this->assertSame(0,DB::table('audit_log')->count());
        $this->assertSame($record['updatedAt'],json_decode(DB::table('projects')->value('data'),true)['updatedAt']);
    }

    public function test_noa_and_legacy_ntp_manual_resolutions_are_rejected(): void
    {
        $record=$this->project(['noa'=>'2020-01-01','ntpDate'=>'2020-01-01']);
        foreach (['noa-warning','noa-active','noa-overdue','ntp-award','ntp-overdue'] as $type) {
            $this->api(['action'=>'saveTimelineRecord','record'=>array_replace($record,['timelineAlertsCompleted'=>[$type]]),'history'=>['action'=>'Rejected manual reminder']])->assertStatus(422);
        }
        $this->assertSame(0,DB::table('history')->count());
        $this->assertSame(0,DB::table('audit_log')->count());
        $this->assertArrayNotHasKey('timelineAlertsCompleted',json_decode(DB::table('projects')->value('data'),true));
    }

    public function test_historical_markers_survive_edits_and_can_still_be_restored(): void
    {
        $record=$this->project(['biddingDate'=>'2020-01-01','timelineAlertsCompleted'=>['material-overdue','final-inspection'],'timelineCompletedMarked'=>true]);
        $record=$this->api(['action'=>'saveProject','record'=>array_replace($record,['title'=>'Preserved history'])])->assertOk()->json('record');
        $this->assertSame(['material-overdue','final-inspection'],$record['timelineAlertsCompleted']);
        $this->assertTrue($record['timelineCompletedMarked']);
        $record=$this->api(['action'=>'saveTimelineRecord','record'=>array_replace($record,['timelineAlertsCompleted'=>[],'timelineCompletedMarked'=>false]),'history'=>['action'=>'Restore historical alerts']])->assertOk()->json('record');
        $this->assertSame([],$record['timelineAlertsCompleted']);
        $this->assertFalse($record['timelineCompletedMarked']);
        $this->api(['action'=>'saveTimelineRecord','record'=>array_replace($record,['timelineAlertsCompleted'=>['material-overdue']]),'history'=>['action'=>'Reject new date bypass']])->assertStatus(422);
        $today=now(config('app.timezone'))->format('Y-m-d');
        $record=$this->api(['action'=>'saveProject','record'=>array_replace($record,['materialTestingDate'=>$today])])->assertOk()->json('record');
        $this->assertEmpty($record['timelineAlertsCompleted']);
        $this->assertContains('material-test',array_column(\App\Support\TrackerMilestones::automaticResolutions($record,new \DateTimeImmutable($today)),'type'));
    }

}
