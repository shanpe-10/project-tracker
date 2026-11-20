<?php
namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use App\Http\Controllers\TrackerBackupController;
use App\Support\TrackerMilestones;
use App\Support\TrackerProjectInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CoreDefectRegressionTest extends TestCase
{
    private ?string $schema = null;
    private array $accountSession;
    protected function setUp(): void {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL integration suite.');
        if (!str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'core_fix_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
    }
    protected function tearDown(): void {
        \Carbon\Carbon::setTestNow();
        if ($this->schema) { DB::statement('SET search_path TO public'); DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE'); }
        parent::tearDown();
    }
    private function install(): void {
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        foreach ([1=>'staff',2=>'admin'] as $id=>$role) {
            DB::table('users')->insert(['id'=>$id,'username'=>'fixture'.$id,'full_name'=>'Fixture '.$id,'role'=>$role,'active'=>true,'can_create'=>true,'can_edit'=>true,'can_import'=>true,'can_export'=>true,'can_delete'=>true,'password_hash'=>Hash::make('fixture-password-2026')]);
            DB::table('tracker_account_sessions')->insert(['user_id'=>$id,'token_hash'=>hash('sha256','fixture-session-'.$id),'started_at'=>time(),'last_activity_at'=>time()]);
        }
        $this->asUser(1);
    }
    private function asUser(int $id): void { $this->accountSession=['tracker_user'=>['id'=>$id],'tracker_csrf'=>'fixture-csrf','tracker_session_token'=>'fixture-session-'.$id]; }
    private function authenticated(): self {
        $this->withSession($this->accountSession); app('session')->save();
        return $this->withCredentials()->withCookie(config('session.cookie'),app('session')->getId())->withHeader('X-CSRF-Token','fixture-csrf');
    }
    private function api(array $body) { return $this->authenticated()->postJson('/api/data.php',$body); }
    private function getApi(string $query) { return $this->authenticated()->getJson('/api/data.php?'.$query); }
    private function project(array $data=[]): int {
        $data += ['title'=>'Fixture project','status'=>'On-going Bidding','approvedBudget'=>'1000','updatedAt'=>'2026-10-29T00:00:00Z'];
        return DB::table('projects')->insertGetId(['data'=>json_encode($data)] + TrackerDataController::projectColumns($data));
    }
    private function stage(string $batch,array $records,int $index=0,?int $total=null,array $extra=[]) {
        return $this->api($extra+['action'=>'bulkSaveProjects','batchId'=>$batch,'records'=>$records,'chunkIndex'=>$index,'expectedRows'=>$total ?? count($records)]);
    }
    private function commit(string $batch) { return $this->api(['action'=>'completeImport','batchId'=>$batch]); }

    public function test_fresh_install_and_repeated_upgrade_preserve_existing_data(): void {
        Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true]);
        $id=$this->project();
        foreach (glob(database_path('migrations/*create_project_editing_presence.php')) as $file) (require $file)->up();
        (require database_path('migrations/2026_10_29_000001_create_deletion_requests.php'))->up();
        (require database_path('migrations/2026_10_30_000002_create_import_staging_chunks.php'))->up();
        Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true]);
        $this->assertDatabaseHas('projects',['id'=>$id,'title'=>'Fixture project']);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('deletion_requests'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('import_staging_chunks'));
    }
    public function test_upgrade_of_an_existing_schema_preserves_accounts_projects_and_logs(): void {
        $this->install(); $id=$this->project();
        DB::table('history')->insert(['project_id'=>$id,'data'=>'{"action":"Existing log"}','updated_at'=>now()]);
        DB::statement('DROP TABLE import_staging_chunks'); // Only this disposable schema.
        Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true]);
        $this->assertDatabaseCount('users',2); $this->assertDatabaseCount('projects',1); $this->assertDatabaseCount('history',1);
        $this->assertDatabaseHas('projects',['id'=>$id,'title'=>'Fixture project']);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('import_staging_chunks'));
    }
    public function test_title_upgrade_preserves_existing_titles_and_imports_the_full_long_title(): void {
        $this->install();
        DB::statement('ALTER TABLE projects ALTER COLUMN title TYPE varchar(255)');
        $id = $this->project(['projectNo' => '1', 'title' => 'Existing project title']);
        $migration = require database_path('migrations/2026_11_11_000002_allow_longer_project_titles.php');
        $migration->up();

        $title = str_repeat('x', 256);
        $batch = 'import_full_project_title';
        $this->stage($batch, [['title' => $title, 'projectNo' => '2']])->assertOk();
        $this->commit($batch)->assertOk()->assertJsonPath('count', 1);
        $this->assertDatabaseHas('projects', ['id' => $id, 'title' => 'Existing project title']);
        $imported = DB::table('projects')->where('project_no', '2')->first();
        $this->assertSame($title, $imported->title);
        $this->assertSame($title, json_decode($imported->data, true)['title']);

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
    public function test_registry_search_matches_accented_titles_case_insensitively(): void {
        $this->install();
        $id = $this->project(['projectNo' => '50', 'title' => 'Audit CAÑAS building']);
        $this->getApi('action=listProjects&search='.rawurlencode('cañas'))
            ->assertOk()->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.id', $id);
    }
    public function test_midnight_refresh_updates_status_without_changing_activity_or_edit_version(): void {
        $this->install();
        $id=$this->project(['status'=>'Pending','contractRef'=>'2026-09-01','contractDuration'=>5,'biddingDate'=>'2026-08-03',
            'preInspection'=>'2026-09-01','materialTestingDate'=>'2026-09-01','ntpDate'=>'2026-09-01']);
        $stamp=DB::table('projects')->where('id',$id)->value('updated_at');
        \Carbon\Carbon::setTestNow('2026-09-01 12:00:00+08:00');
        \App\Support\TrackerProjectStatus::refresh();
        $this->assertSame('Pending',DB::table('projects')->where('id',$id)->value('project_status'));
        $version=new \ReflectionMethod(TrackerDataController::class,'projectVersion');
        $before=$version->invoke(new TrackerDataController);
        \Carbon\Carbon::setTestNow('2026-10-30 12:00:00+08:00');
        $this->assertSame(0,Artisan::call('tracker:project-status-sync'));
        $this->assertSame('Final-inspection',DB::table('projects')->where('id',$id)->value('project_status'));
        $this->assertSame($stamp,DB::table('projects')->where('id',$id)->value('updated_at'));
        $this->assertNotSame($before,$version->invoke(new TrackerDataController));
        $this->assertDatabaseCount('history',0);
    }
    public function test_invalid_later_chunk_leaves_no_projects_history_or_numbering_changes(): void {
        $this->install(); $batch='atomic_invalid_later_chunk';
        $this->api(['action'=>'bulkSaveProjects','records'=>[['title'=>'Missing batch']]])->assertUnprocessable();
        $this->assertDatabaseCount('import_jobs',0);
        $this->stage($batch,[['title'=>'Valid first row','projectNo'=>'8']],0,2)->assertOk();
        $this->assertDatabaseCount('projects',0);
        $this->stage($batch,[['title'=>'']],1,2)->assertUnprocessable();
        $this->commit($batch)->assertUnprocessable();
        $this->assertDatabaseCount('projects',0); $this->assertDatabaseCount('history',0);
        $this->assertSame(1,(int)DB::table('project_number_sequences')->value('next_number'));
        $this->api(['action'=>'rollbackImport','batchId'=>$batch])->assertOk();
        $this->assertDatabaseCount('import_staging_chunks',0);
    }
    public function test_final_commit_is_atomic_and_retry_does_not_duplicate_records(): void {
        $this->install(); $id=$this->project(['projectNo'=>'1']);
        $batch='atomic_duplicate_numbers';
        $this->stage($batch,[['title'=>'Valid row','projectNo'=>'2'],['title'=>'Conflicting row','projectNo'=>'1']])->assertOk();
        $this->commit($batch)->assertUnprocessable();
        $this->assertDatabaseCount('projects',1); $this->assertDatabaseCount('history',0);
        $batch='atomic_successful_upload';
        $rows=[['title'=>'Archived new row','projectNo'=>'8','status'=>'Completed']];
        $this->stage($batch,$rows,0,2,['archive'=>true])->assertOk();
        $this->stage($batch,$rows,0,2,['archive'=>true])->assertOk();
        $this->stage($batch,[['title'=>'Second archived row','projectNo'=>'9']],1,2,['archive'=>true])->assertOk();
        $this->commit($batch)->assertOk(); $this->commit($batch)->assertOk();
        $this->assertDatabaseCount('projects',3); $this->assertDatabaseCount('history',2);
        $this->assertNotNull(DB::table('projects')->where('project_no','8')->value('archived_at'));
        $this->assertSame(10,(int)DB::table('project_number_sequences')->value('next_number'));
        $this->api(['action'=>'rollbackImport','batchId'=>$batch])->assertUnprocessable();
        $this->assertDatabaseHas('projects',['id'=>$id]);
    }
    public function test_legacy_save_and_import_append_logs_and_existing_entries_cannot_be_replaced(): void {
        $this->install();
        $record=$this->api(['action'=>'saveProject','record'=>['title'=>'Logged create','approvedBudget'=>'1000']])->assertOk()->json('record');
        $this->assertDatabaseCount('history',1);
        $record['approvedBudget']='2000';
        $record=$this->api(['action'=>'saveProject','record'=>$record])->assertOk()->json('record');
        $history=DB::table('history')->orderBy('id')->first(); $original=$history->data;
        $this->api(['action'=>'saveHistory','record'=>['id'=>$history->id,'projectId'=>$record['id'],'action'=>'Replacement']])->assertUnprocessable();
        $this->assertSame($original,DB::table('history')->where('id',$history->id)->value('data'));
        $record['approvedBudget']='2500';
        $this->stage('logged_import_overwrite',[$record])->assertOk(); $this->commit('logged_import_overwrite')->assertOk();
        $this->assertDatabaseCount('history',3);
        $this->api(['action'=>'rollbackImport','batchId'=>'logged_import_overwrite'])->assertOk();
        $this->assertDatabaseCount('history',4);
        $last=json_decode(DB::table('history')->orderByDesc('id')->value('data'),true);
        $this->assertSame('Import rollback',$last['action']); $this->assertSame(1,$last['actor']['id']);
        $this->assertSame('2000',json_decode(DB::table('projects')->where('id',$record['id'])->value('data'),true)['approvedBudget']);
    }
    public function test_future_actual_dates_are_rejected_in_legacy_edit_upload_and_import(): void {
        $this->install(); $id=$this->project();
        $record=json_decode(DB::table('projects')->where('id',$id)->value('data'),true)+['id'=>$id];
        $future=now('Asia/Manila')->addYear()->toDateTimeImmutable();
        while(!TrackerMilestones::isWorkday($future)) $future=$future->modify('+1 day');
        foreach(['completion','swa'] as $field) {
            $invalid=$record+[$field=>$future->format('Y-m-d')];
            $this->api(['action'=>'saveProject','record'=>$invalid])->assertUnprocessable();
            $this->authenticated()->postJson('/api/project-edit.php',['projectId'=>$id,'record'=>json_encode($invalid),'deleteIds'=>'[]'])->assertUnprocessable();
            $this->stage('future_actual_'.$field,[$invalid])->assertUnprocessable();
            $legacy=$invalid; $legacy['status']=$field==='swa'?'Released':'Completed';
            $this->assertNotContains(TrackerProjectInput::automaticStatus($legacy),['Completed','Released']);
        }
        $this->assertDatabaseCount('history',0);
    }
    public function test_password_reset_revokes_target_only_and_clears_presence(): void {
        $this->install(); $id=$this->project();
        $staleAccount=\App\Models\User::findOrFail(1);
        DB::table('project_editing_presence')->insert(['user_id'=>1,'editor_token'=>str_repeat('a',32),'project_id'=>$id,'session_key'=>str_repeat('b',64),'last_seen_at'=>now()]);
        $this->asUser(2);
        $this->authenticated()->postJson('/api/auth.php',['action'=>'updateUser','id'=>1,'username'=>'fixture1','fullName'=>'Fixture 1','role'=>'staff','active'=>true,'password'=>'new-fixture-password-2026','current_password'=>'fixture-password-2026'])->assertOk();
        $this->assertDatabaseMissing('tracker_account_sessions',['user_id'=>1]);
        $this->assertDatabaseHas('tracker_account_sessions',['user_id'=>2]);
        $this->assertDatabaseCount('project_editing_presence',0);
        $this->asUser(1); $this->api(['action'=>'saveProject','record'=>['title'=>'Stale session','approvedBudget'=>'1']])->assertUnauthorized();
        $this->assertTrue(Hash::check('new-fixture-password-2026',DB::table('users')->where('id',1)->value('password_hash')));
        try { \App\Support\TrackerSession::start(\Illuminate\Http\Request::create('/'),$staleAccount); $this->fail('A login verified before the reset must not establish a session afterward.'); }
        catch (\Illuminate\Validation\ValidationException $error) { $this->assertArrayHasKey('password',$error->errors()); }
        $this->assertDatabaseMissing('tracker_account_sessions',['user_id'=>1]);
        $this->asUser(2);
        $this->authenticated()->postJson('/api/auth.php',['action'=>'updateUser','id'=>2,'username'=>'fixture2','fullName'=>'Fixture 2','role'=>'admin','active'=>true,'password'=>'new-admin-fixture-password','current_password'=>'fixture-password-2026'])->assertOk()->assertJsonPath('signInRequired',true);
        $this->api(['action'=>'saveProject','record'=>['title'=>'Old Superadmin session']])->assertUnauthorized();
    }
    public function test_deadline_status_is_current_across_list_dashboard_details_and_export_without_an_edit(): void {
        $this->install();
        $id=$this->project(['status'=>'Pending','contractRef'=>'2026-09-01','contractDuration'=>5,'biddingDate'=>'2026-08-03']);
        $this->getApi('action=listProjects&status=Final-inspection')->assertOk()->assertJsonPath('totalRecords',1)->assertJsonPath('projects.0.status','Final-inspection');
        $this->getApi('action=dashboard')->assertOk()->assertJsonPath('statusCounts.Final-inspection',1);
        $data=$this->getApi('')->assertOk()->json('projects');
        $this->assertSame('Final-inspection',$data[0]['status']);
        $this->getApi('action=listProjects&format=csv&status=Final-inspection')->assertOk();
        $saved=json_decode(DB::table('projects')->where('id',$id)->value('data'),true);
        $this->assertSame('2026-10-29T00:00:00Z',$saved['updatedAt']);
        $this->assertDatabaseCount('history',0); // Clock projection does not pretend a user edited the record.
    }
    public function test_permission_revocation_is_refreshed_by_session_and_enforced_on_save(): void {
        $this->install();
        DB::table('users')->where('id',1)->update(['can_create'=>false,'can_edit'=>false]);
        $this->authenticated()->postJson('/api/auth.php',['action'=>'session'])->assertOk()->assertJsonPath('user.canCreate',false)->assertJsonPath('user.canEdit',false);
        $this->api(['action'=>'saveProject','record'=>['title'=>'Denied','approvedBudget'=>'1']])->assertForbidden();
    }
    public function test_numeric_validation_is_consistent_and_valid_decimals_are_preserved(): void {
        $this->install();
        foreach(['approvedBudget','bidAmount','materialTestingAmount','contractDuration'] as $field) {
            foreach(['-1','banana','NaN','Infinity','1e9',true] as $value) {
                $row=array_replace(['title'=>'Invalid numeric','approvedBudget'=>'100'],[$field=>$value]);
                $this->api(['action'=>'saveProject','record'=>$row])->assertUnprocessable();
                $this->stage('numeric_invalid_'.$field,[$row])->assertUnprocessable();
            }
        }
        $row=['title'=>'Valid decimals','approvedBudget'=>'1,234.50','ldAmount'=>'123.45','ldNoOfDays'=>'.5','ldActualDuration'=>'30.5','contractDuration'=>'30.5'];
        $saved=$this->api(['action'=>'saveProject','record'=>$row])->assertOk()->json('record');
        $this->assertSame('123.45',$saved['ldAmount']); $this->assertSame('.5',$saved['ldNoOfDays']);
        $this->assertSame(30.5,$saved['contractDuration']);
    }

    public function test_recovery_package_restores_workflow_and_audit_evidence_in_an_isolated_schema(): void {
        $this->install(); $id=$this->project();
        $requestId=DB::table('deletion_requests')->insertGetId(['target_type'=>'project','status'=>'rejected','requested_by'=>1,'requester'=>'{"id":1}','reason'=>'FIXTURE_REQUEST','requested_at'=>now(),'reviewer'=>'{"id":2}','reviewed_at'=>now(),'decision_note'=>'FIXTURE_DECISION']);
        DB::table('deletion_request_targets')->insert(['request_id'=>$requestId,'target_type'=>'project','target_id'=>$id,'project_id'=>$id,'fingerprint'=>str_repeat('a',64),'snapshot'=>'{}']);
        DB::table('audit_log')->insert(['actor_id'=>1,'actor_username'=>'fixture1','action'=>'deletion.rejected','entity_type'=>'deletion_request','summary'=>'FIXTURE_AUDIT','created_at'=>now()]);
        $this->stage('recovery_pending_import',[['title'=>'Pending upload']])->assertOk();
        $destination=storage_path('test-recovery-'.bin2hex(random_bytes(5)));
        $archive=(new \ReflectionMethod(TrackerBackupController::class,'createBackup'))->invoke(new TrackerBackupController,$destination);
        $zip=new \ZipArchive; $this->assertTrue($zip->open($archive)===true); $dump=$zip->getFromName('database.sql'); $zip->close();
        $this->assertStringContainsString('FIXTURE_DECISION',$dump); $this->assertStringContainsString('FIXTURE_AUDIT',$dump);
        $this->assertDoesNotMatchRegularExpression('/COPY .*\.users /',$dump);
        $restore=$this->schema.'_restore'; $file=$destination.'/restore.sql'; $pass=$destination.'/pgpass';
        try {
            DB::statement('CREATE SCHEMA "'.$restore.'"'); DB::statement('SET search_path TO "'.$restore.'"');
            DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
            DB::table('users')->insert(['id'=>1,'username'=>'restore_fixture','full_name'=>'Restore Fixture','role'=>'staff','active'=>true,'password_hash'=>'fixture']);
            DB::table('project_number_sequences')->delete();
            $dump=str_replace([$this->schema.'.','"'.$this->schema.'".'],[$restore.'.','"'.$restore.'".'],$dump);
            file_put_contents($file,$dump);
            $cfg=DB::connection()->getConfig(); $escape=fn($s)=>str_replace(['\\',':'],['\\\\','\\:'],(string)$s);
            file_put_contents($pass,implode(':',array_map($escape,[$cfg['host'],$cfg['port'],$cfg['database'],$cfg['username'],$cfg['password']])).PHP_EOL); @chmod($pass,0600);
            $process=new Process([dirname(config('database.pg_dump')).'/psql'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''),'--no-password','--host='.$cfg['host'],'--port='.$cfg['port'],'--username='.$cfg['username'],'--dbname='.$cfg['database'],'--set=ON_ERROR_STOP=on','--single-transaction','--file='.$file],null,['PGPASSFILE'=>$pass,'PGPASSWORD'=>false]);
            $process->mustRun();
            $this->assertDatabaseCount('projects',1); $this->assertDatabaseCount('deletion_requests',1); $this->assertDatabaseCount('deletion_request_targets',1); $this->assertDatabaseCount('import_staging_chunks',1);
            $this->assertSame('FIXTURE_DECISION',DB::table('deletion_requests')->value('decision_note'));
            $this->assertSame('FIXTURE_AUDIT',DB::table('audit_log')->value('summary'));
        } finally {
            @unlink($pass); @unlink($file); DB::statement('SET search_path TO "'.$this->schema.'"'); DB::statement('DROP SCHEMA IF EXISTS "'.$restore.'" CASCADE');
        }
    }
}
