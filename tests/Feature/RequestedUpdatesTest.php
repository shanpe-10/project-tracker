<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use App\Support\TrackerMilestones;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RequestedUpdatesTest extends TestCase
{
    private ?string $schema = null;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useWeekdayCalendarFixture();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL integration test.');
        if (!str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'requested_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        foreach (['admin','admin','staff'] as $i=>$role) {
            $values = ['id'=>$i+1,'username'=>$role.($i===1?'2':''),'full_name'=>$role,'password_hash'=>password_hash('test-password-12',PASSWORD_BCRYPT),'role'=>$role,'active'=>true];
            foreach (['can_create','can_edit','can_import','can_export','can_delete','can_delete_all','can_resolve_alerts','can_archive'] as $permission) $values[$permission]=true;
            DB::table('users')->insert($values);
        }
        DB::statement("SELECT setval(pg_get_serial_sequence('users','id'),3,true)");
        $this->asUser(3);
    }

    protected function tearDown(): void
    {
        if ($this->schema) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    private function asUser(int $id): void
    {
        $this->session=['tracker_user'=>['id'=>$id],'tracker_csrf'=>'request-token','tracker_created_at'=>time(),'tracker_last_activity'=>time()];
    }

    private function api(array $body,string $endpoint='data.php')
    {
        $response = $this->withSession($this->session)->withHeader('X-CSRF-Token','request-token')->postJson('/api/'.$endpoint,$body); return $this->finishImportFixture($response,$body,$endpoint);
    }

    private function project(array $data=[]): array
    {
        $data += ['title'=>'Project for alerts','projectNo'=>'123','status'=>'Pending','biddingDate'=>'2025-01-31','updatedAt'=>'2026-10-06T00:00:00.000000Z'];
        $id=DB::table('projects')->insertGetId(['data'=>json_encode($data)]+TrackerDataController::projectColumns($data));
        return $data+['id'=>$id];
    }

    public function test_dates_use_calendar_months_and_contract_date_with_decimal_days(): void
    {
        foreach (['2026-01-31'=>'2026-03-02','2024-01-31'=>'2024-02-29','2026-12-31'=>'2027-02-01'] as $start=>$due) {
            $dates=TrackerMilestones::dueDates(['biddingDate'=>$start]);
            foreach (['preInspectionDueDate','ntpDueDate','materialTestingDueDate'] as $key) $this->assertSame($due,$dates[$key]);
        }
        $dates=TrackerMilestones::dueDates(['contractRef'=>'2026-10-01','ntpDate'=>'2026-10-20','contractDuration'=>30.5,'contractEndDate'=>'2028-01-01','ldNewCompletionDate'=>'2029-01-01']);
        $this->assertSame('2026-11-12',$dates['contractDueDate']);
        $this->assertSame($dates['contractDueDate'],$dates['finalInspectionDueDate']);
        foreach (['60 CD','days',true,[],0,-1,'INF','NaN',40000] as $value) $this->assertNull(TrackerMilestones::duration($value));
        foreach ([30,30.5,'30.5','3e1'] as $value) $this->assertNotNull(TrackerMilestones::duration($value));
    }

    public function test_final_inspection_appears_seven_days_before_due_and_resolves_from_date(): void
    {
        $record=['status'=>'Pending','contractRef'=>'2026-10-01','contractDuration'=>30];
        $types=fn ($day,$extra=[])=>array_column(TrackerMilestones::alerts(array_replace($record,$extra),[],new \DateTimeImmutable($day)),'type');
        $this->assertNotContains('final-inspection',$types('2026-11-02'));
        $this->assertContains('final-inspection',$types('2026-11-03'));
        $this->assertContains('final-inspection',$types('2026-11-12'));
        $this->assertContains('final-inspection-overdue',$types('2026-11-13'));
        $this->assertNotContains('final-inspection',$types('2026-10-24',['finalInspection'=>'2026-10-24']));
    }

    public function test_custom_final_inspection_reminder_changes_the_alert_boundary(): void
    {
        $record=['status'=>'Pending','contractRef'=>'2026-10-01','contractDuration'=>30];
        $types=fn ($day)=>array_column(TrackerMilestones::alerts($record,['finalInspection'=>14],new \DateTimeImmutable($day)),'type');
        $this->assertNotContains('final-inspection',$types('2026-10-22'));
        $this->assertContains('final-inspection',$types('2026-10-23'));
        $this->assertContains('final-inspection-overdue',$types('2026-11-13'));
    }

    public function test_numeric_duration_is_enforced_in_edit_import_and_saved_contract_date(): void
    {
        $record=$this->project(['contractRef'=>'2026-10-01']);
        foreach (['30 days',true,[],0,-3] as $duration) $this->api(['action'=>'saveProject','record'=>array_replace($record,['contractDuration'=>$duration])])->assertStatus(422);
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'numeric_contract_test','records'=>[['title'=>'Invalid import','contractDuration'=>'60CD']]])->assertStatus(422);
        $this->api(['action'=>'saveProject','record'=>array_replace($record,['contractDuration'=>30.5,'ntpDate'=>'2026-10-12'])])->assertOk()->assertJsonPath('record.contractEndDate','2026-11-12')->assertJsonPath('record.contractDuration',30.5);
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'numeric_contract_valid','records'=>[['title'=>'Decimal import','contractDuration'=>2.5,'contractRef'=>'2026-10-01']]])->assertOk();
        $data=json_decode(DB::table('projects')->where('title','Decimal import')->value('data'),true);
        $this->assertSame('2026-10-05',$data['contractEndDate']);
    }

    public function test_noa_and_ntp_dates_resolve_all_aliases_and_remain_visible(): void
    {
        $project=$this->project(['noa'=>'2025-02-01','ntpDate'=>'2025-02-02']);
        $this->withSession($this->session)->getJson('/api/data.php?action=projectAlerts&projectId='.$project['id'])->assertOk()
            ->assertJsonFragment(['label'=>'Notice of Award','status'=>'Resolved','doneDate'=>'2025-02-01'])
            ->assertJsonFragment(['label'=>'Notice to Proceed','status'=>'Resolved','doneDate'=>'2025-02-02']);
        $notifications=$this->withSession($this->session)->getJson('/api/data.php?action=notifications')->assertOk()->json();
        $types=array_column($notifications['groups'][0]['alerts'],'type');
        $this->assertFalse((bool)array_filter($types,fn ($type)=>str_starts_with($type,'noa') || str_starts_with($type,'ntp')));
        $this->assertSame('2025-02-01',json_decode(DB::table('projects')->value('data'),true)['noa']);
        $this->assertCount(1,TrackerMilestones::automaticResolutions(['ntpDate'=>'2025-02-02'],new \DateTimeImmutable('2026-10-06')));
    }

    public function test_dashboard_paginates_projects_without_splitting_alerts_and_notifications_are_unfiltered(): void
    {
        for ($i=1;$i<=12;$i++) $this->project(['projectNo'=>(string)$i,'title'=>'Alert project '.$i]);
        $page=$this->withSession($this->session)->getJson('/api/data.php?action=dashboard&alertPageSize=10')->assertOk()->json();
        $this->assertCount(10,$page['alertGroups']);
        $this->assertCount(30,$page['alerts']);
        $this->assertSame(12,$page['alertProjectTotal']);
        $this->assertSame(2,$page['alertTotalPages']);
        $this->withSession($this->session)->getJson('/api/data.php?action=dashboard&alertSearch=no-such-alert')->assertOk()->assertJsonCount(0,'alerts');
        $this->withSession($this->session)->getJson('/api/data.php?action=notifications')->assertOk()->assertJsonCount(12,'groups')->assertJsonPath('alertTotal',36);
        $this->withSession($this->session)->getJson('/api/data.php?action=dashboard&alertPageSize=10&alertPage=2')->assertOk()->assertJsonCount(2,'alertGroups')->assertJsonCount(6,'alerts');
    }

    public function test_filters_combine_and_clearing_parameters_restores_all_records(): void
    {
        $this->project(['projectNo'=>'1','title'=>'Road Brgy 1','status'=>'Pending','biddingDate'=>'2026-01-31']);
        $this->project(['projectNo'=>'2','title'=>'Road Brgy 2','status'=>'Completed','biddingDate'=>'2026-02-10','completion'=>'2026-03-01']);
        $this->project(['projectNo'=>'3','title'=>'Archived Road','archivedAt'=>'2026-01-01']);
        DB::table('projects')->where('project_no','3')->update(['archived_at'=>'2026-01-01']);
        $this->withSession($this->session)->getJson('/api/data.php?action=listProjects&lifecycle=all&status=Pending&brgy=1&dateField=biddingDate&month=1&year=2026')->assertOk()->assertJsonPath('totalRecords',1);
        $this->withSession($this->session)->getJson('/api/data.php?action=listProjects&lifecycle=all&status=Completed')->assertOk()->assertJsonPath('totalRecords',1);
        $this->withSession($this->session)->getJson('/api/data.php?action=listProjects&lifecycle=all')->assertOk()->assertJsonPath('totalRecords',2);
        $this->withSession($this->session)->getJson('/api/data.php?action=listProjects&lifecycle=archived')->assertOk()->assertJsonPath('totalRecords',1);
    }

    private function asDefaultAdmin(): void
    {
        DB::table('users')->where('id', 1)->update(array_fill_keys(['can_create','can_edit','can_import','can_export','can_delete','can_delete_all','can_resolve_alerts','can_archive'], false));
        $this->asUser(1);
    }

    public function test_admin_has_full_project_access_by_default_and_staff_cannot_manage_accounts(): void
    {
        $record=$this->project();
        $this->asUser(3);
        foreach (['listManagedUsers','createUser','updateUser','deleteUser','setUserPermission','approveUser','rejectUser'] as $action) $this->api(['action'=>$action,'id'=>3],'auth.php')->assertForbidden();
        $this->asDefaultAdmin();
        $user=$this->api(['action'=>'session'],'auth.php')->assertOk()->json('user');
        foreach (['canCreate','canEdit','canImport','canExport','canDelete','canDeleteAll','canResolveAlerts','canArchive'] as $permission) $this->assertTrue($user[$permission], $permission);
        $record=$this->api(['action'=>'saveProject','record'=>array_replace($record,['title'=>'Admin project edit'])])->assertOk()->assertJsonPath('record.title','Admin project edit')->json('record');
        $record=$this->withSession($this->session)->withHeader('X-CSRF-Token','request-token')->post('/api/project-edit.php',['projectId'=>$record['id'],'record'=>json_encode(array_replace($record,['title'=>'Admin form edit']))])->assertOk()->assertJsonPath('record.title','Admin form edit')->json('record');
        $created=$this->api(['action'=>'saveProject','record'=>['title'=>'Admin created project','biddingDate'=>'2026-01-30','approvedBudget'=>'100','winningBidder'=>'Demo builder']])->assertOk()->json('record');
        $this->api(['action'=>'setProjectArchived','id'=>$created['id'],'archived'=>true])->assertOk();
        $this->assertNotNull(DB::table('projects')->where('id',$created['id'])->value('archived_at'));
        $this->api(['action'=>'setProjectArchived','id'=>$created['id'],'archived'=>false])->assertOk();
        $this->assertNull(DB::table('projects')->where('id',$created['id'])->value('archived_at'));
        $csv=$this->withSession($this->session)->get('/api/data.php?action=listProjects&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Admin created project',$csv);
        $this->withSession($this->session)->get('/api/data.php?action=listProjects&format=xlsx')->assertOk();
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'requested_admin_import','records'=>[['title'=>'Admin imported project']]])->assertOk()->assertJsonPath('count',1);
        $this->assertSame(1,DB::table('projects')->where('title','Admin imported project')->count());
        $this->api(['action'=>'rollbackImport','batchId'=>'requested_admin_import'])->assertStatus(422);
        $this->assertSame(1,DB::table('projects')->where('title','Admin imported project')->count());
        $this->api(['action'=>'listAuditLog'],'auth.php')->assertOk();
        $this->withSession($this->session)->getJson('/api/backups.php')->assertOk();
        $this->api(['action'=>'saveTimelineSettings','settings'=>['noa'=>10,'ntp'=>30,'material'=>15,'completion'=>15,'finalInspection'=>30,'bidding'=>15]])->assertOk()->assertJsonPath('settings.finalInspection',30);
        $this->api(['action'=>'saveTimelineRecord','record'=>array_replace($record,['timelineAlertsCompleted'=>['noa-overdue']]),'history'=>['projectId'=>$record['id'],'action'=>'timeline-mark-done','details'=>'Resolved']])->assertUnprocessable();
    }

    public function test_admin_can_delete_and_clear_projects_without_staff_permission_grants(): void
    {
        config(['tracker.attachments'=>storage_path('framework/testing/admin-access-'.bin2hex(random_bytes(8)))]);
        $record=$this->project();
        $this->asDefaultAdmin();
        $this->withSession($this->session)->withHeader('X-CSRF-Token','request-token');
        $this->confirmDirectFixture(['targetType'=>'project','id'=>$record['id']], 'test-password-12')->assertOk();
        $this->assertSame(0,DB::table('projects')->count());
        $this->project(['projectNo'=>'124']);
        $this->confirmDirectFixture(['targetType'=>'project','all'=>true], 'test-password-12')->assertOk();
        $this->assertSame(0,DB::table('projects')->count());
        $this->assertSame(1,(int)DB::table('project_number_sequences')->where('sequence_name','projects')->value('next_number'));
    }

    public function test_admin_crud_role_changes_and_self_protection(): void
    {
        $this->asUser(2);
        $this->api(['action'=>'listManagedUsers'],'auth.php')->assertOk()->assertJsonCount(3,'users');
        $this->api(['action'=>'createUser','username'=>'new.staff','fullName'=>'New account','role'=>'staff','active'=>true,'password'=>'new-password-12'],'auth.php')->assertOk();
        $id=(int)DB::table('users')->where('username','new.staff')->value('id');
        $hash=DB::table('users')->where('id',$id)->value('password_hash');
        $this->api(['action'=>'updateUser','id'=>$id,'username'=>'new.staff','fullName'=>'Edited Admin','role'=>'admin','active'=>true],'auth.php')->assertOk();
        $this->assertSame($hash,DB::table('users')->where('id',$id)->value('password_hash'));
        $this->api(['action'=>'deleteUser','id'=>2],'auth.php')->assertStatus(422);
        $this->api(['action'=>'updateUser','id'=>2,'username'=>'admin2','fullName'=>'Admin','role'=>'staff','active'=>true],'auth.php')->assertStatus(422);
        $this->api(['action'=>'deleteUser','id'=>$id],'auth.php')->assertOk();
        $this->assertNull(DB::table('users')->where('id',$id)->first());
        $this->api(['action'=>'listAuditLog'],'auth.php')->assertOk();
        $this->withSession($this->session)->getJson('/api/backups.php')->assertOk();
    }

    public function test_revoked_permissions_are_refreshed_and_account_csrf_is_required(): void
    {
        $record=$this->project();
        $this->asUser(2);
        $this->api(['action'=>'setUserPermission','id'=>3,'permission'=>'canResolveAlerts','allowed'=>false],'auth.php')->assertOk();
        $this->asUser(3);
        $this->api(['action'=>'saveProject','record'=>array_replace($record,['timelineAlertsCompleted'=>['noa-overdue']])])->assertForbidden();
        $this->withSession($this->session)->withHeader('X-CSRF-Token','request-token')->post('/api/project-edit.php',['projectId'=>$record['id'],'record'=>json_encode(array_replace($record,['timelineAlertsCompleted'=>['noa-overdue']]))])->assertForbidden();
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'manual_flags_import','records'=>[['title'=>'Imported flags','timelineAlertsCompleted'=>['noa-overdue']]]])->assertOk();
        $this->assertArrayNotHasKey('timelineAlertsCompleted',json_decode(DB::table('projects')->where('title','Imported flags')->value('data'),true));
        $this->asUser(2);
        $this->api(['action'=>'setUserPermission','id'=>3,'permission'=>'canEdit','allowed'=>false],'auth.php')->assertOk();
        $this->api(['action'=>'setUserPermission','id'=>3,'permission'=>'canResolveAlerts','allowed'=>false],'auth.php')->assertOk();
        $this->asUser(3);
        $this->api(['action'=>'saveProject','record'=>$record])->assertForbidden();
        $this->asUser(2);
        $this->withSession($this->session)->withHeader('X-CSRF-Token','wrong')->postJson('/api/auth.php',['action'=>'deleteUser','id'=>3])->assertStatus(419);
    }
}
