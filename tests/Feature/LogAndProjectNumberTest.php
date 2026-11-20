<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LogAndProjectNumberTest extends TestCase
{
    private int $actor = 2;
    private const PASSWORD = 'Fixture-admin-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('Isolated PostgreSQL database required.');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        $this->useWeekdayCalendarFixture();
        foreach ([1=>'staff', 2=>'admin'] as $id=>$role) {
            DB::table('users')->insert(['id'=>$id,'username'=>'numbers'.$id,'full_name'=>'Numbers '.$id,'role'=>$role,'active'=>true,
                'can_create'=>true,'can_edit'=>true,'can_import'=>true,'can_delete'=>true,'password_hash'=>Hash::make(self::PASSWORD)]);
            DB::table('tracker_account_sessions')->insert(['user_id'=>$id,'token_hash'=>hash('sha256','numbers-'.$id),'started_at'=>time(),'last_activity_at'=>time()]);
        }
    }

    private function api(array $body, string $endpoint='data.php')
    {
        $this->withSession(['tracker_user'=>['id'=>$this->actor],'tracker_csrf'=>'numbers-csrf','tracker_session_token'=>'numbers-'.$this->actor]);
        return $this->withHeader('X-CSRF-Token','numbers-csrf')->postJson('/api/'.$endpoint,$body);
    }

    private function project(string $number, bool $archived=false): int
    {
        $data=['projectNo'=>$number,'title'=>'Project '.$number,'approvedBudget'=>'1000','updatedAt'=>'2026-10-01T00:00:00Z'];
        if ($archived) $data['archivedAt']='2026-10-02T00:00:00Z';
        return DB::table('projects')->insertGetId(['data'=>json_encode($data),'archived_at'=>$archived?now():null]+TrackerDataController::projectColumns($data));
    }

    private function next(): string
    {
        $this->api(['action'=>'session'],'auth.php')->assertOk();
        return $this->getJson('/api/data.php?action=nextProjectNumber')->assertOk()->json('nextProjectNumber');
    }

    private function create(array $extra=[])
    {
        return $this->api(['action'=>'saveProject','record'=>$extra+['title'=>'New project','approvedBudget'=>'1000']]);
    }

    private function deleteReviewed(array $target): void
    {
        $preview=$this->api(['action'=>'previewDirect']+$target,'deletion-requests.php')->assertOk()->json('selection');
        $token=$this->api(['action'=>'prepareDirect','expectedVersion'=>$preview['version']]+$target,'deletion-requests.php')->assertOk()->json('confirmationToken');
        $this->api(['action'=>'confirmDirect','confirmationToken'=>$token,'confirmed'=>true,'current_password'=>self::PASSWORD],'deletion-requests.php')->assertOk();
    }

    private function import(array $records, string $suffix='valid')
    {
        $batch='project-number-test-'.$suffix;
        $this->api(['action'=>'bulkSaveProjects','batchId'=>$batch,'records'=>$records])->assertOk();
        return $this->api(['action'=>'completeImport','batchId'=>$batch]);
    }

    public function test_log_ids_are_permanent_across_pagination_and_date_filters(): void
    {
        for($number=1;$number<=103;$number++) {
            DB::table('audit_log')->insert(['actor_id'=>2,'actor_username'=>'numbers2','action'=>'project.saved','entity_type'=>'project',
                'summary'=>'Tracked event '.$number,'created_at'=>$number===50?'2026-10-05 03:00:00':'2026-10-06 03:00:00']);
        }
        $first=$this->api(['action'=>'listAuditLog','page'=>1],'auth.php')->assertOk();
        $first->assertJsonPath('entries.0.id',103)->assertJsonCount(100,'entries')->assertJsonPath('hasMorePages',true);
        $this->api(['action'=>'listAuditLog','page'=>2],'auth.php')->assertOk()->assertJsonPath('entries.0.id',3)->assertJsonPath('entries.2.id',1);
        $this->api(['action'=>'listAuditLog','fromDate'=>'2026-10-05','toDate'=>'2026-10-05'],'auth.php')
            ->assertOk()->assertJsonCount(1,'entries')->assertJsonPath('entries.0.id',50);
        $this->assertDatabaseCount('audit_log',103);
    }

    public function test_clearing_visible_logs_does_not_restart_log_numbers(): void
    {
        $old=DB::table('audit_log')->insertGetId(['actor_id'=>2,'actor_username'=>'numbers2','action'=>'project.saved','entity_type'=>'project',
            'summary'=>'Old event','created_at'=>'2020-01-01 00:00:00']);
        $this->api(['action'=>'clearAuditLogView','confirmation'=>'CLEAR HISTORY'],'auth.php')->assertOk();
        $this->api(['action'=>'listAuditLog'],'auth.php')->assertOk()->assertJsonPath('entries.0.id',$old+1);
        $this->assertDatabaseHas('audit_log',['id'=>$old]);
    }

    public function test_staff_cannot_read_administrator_log_numbers(): void
    {
        $this->actor=1;
        $this->api(['action'=>'listAuditLog'],'auth.php')->assertForbidden();
    }

    public function test_deleted_middle_number_is_not_reused_and_remaining_numbers_stay_fixed(): void
    {
        $ids=[];foreach(['1','2','3','4'] as $number)$ids[$number]=$this->project($number);
        $this->deleteReviewed(['targetType'=>'project','id'=>$ids['3']]);
        $this->assertSame('5',$this->next());
        $this->create(['projectNo'=>'999'])->assertOk()->assertJsonPath('record.projectNo','5');
        $this->assertSame(['1','2','4','5'],DB::table('projects')->orderBy('id')->pluck('project_no')->all());
    }

    public function test_deleting_latest_project_reuses_its_number_despite_a_stale_counter(): void
    {
        foreach(['1','2','3'] as $number)$this->project($number);
        $last=$this->project('4');
        DB::table('project_number_sequences')->update(['next_number'=>500]);
        $this->deleteReviewed(['targetType'=>'project','id'=>$last]);
        $this->assertSame('4',$this->next());
        $this->create()->assertOk()->assertJsonPath('record.projectNo','4');
        $this->create()->assertOk()->assertJsonPath('record.projectNo','5');
    }

    public function test_archives_reserve_numbers_and_deleting_all_restarts_at_one(): void
    {
        $this->project('1');$this->project('4',true);
        $this->assertSame('5',$this->next());
        $this->deleteReviewed(['targetType'=>'project','all'=>true]);
        $this->assertSame('1',$this->next());
        $this->create()->assertOk()->assertJsonPath('record.projectNo','1');
    }

    public function test_empty_active_list_explains_reserved_archive_numbers_and_truly_empty_list_starts_at_one(): void
    {
        $this->project('110',true);
        $this->api(['action'=>'session'],'auth.php')->assertOk();
        $this->getJson('/api/data.php?action=nextProjectNumber')->assertOk()->assertJsonPath('nextProjectNumber','111')
            ->assertJsonPath('activeProjectCount',0)->assertJsonPath('archivedProjectCount',1)->assertJsonPath('highestArchivedProjectNumber','110');
        $this->deleteReviewed(['targetType'=>'project','all'=>true]);
        $this->getJson('/api/data.php?action=nextProjectNumber')->assertOk()->assertJsonPath('nextProjectNumber','1')
            ->assertJsonPath('projectCount',0)->assertJsonPath('archivedProjectCount',0)->assertJsonPath('highestArchivedProjectNumber',null);
    }

    public function test_missing_or_outdated_counter_cannot_duplicate_an_active_or_archived_number(): void
    {
        $this->project('110');$this->project('111',true);
        DB::table('project_number_sequences')->delete();
        $this->assertSame('112',$this->next());
        $this->create()->assertOk()->assertJsonPath('record.projectNo','112');
        $this->create()->assertOk()->assertJsonPath('record.projectNo','113');
    }

    public function test_failed_project_save_does_not_consume_a_project_number(): void
    {
        $this->project('1');
        DB::statement("ALTER TABLE audit_log ADD CONSTRAINT number_test_failure CHECK (action <> 'project.saved')");
        $this->create()->assertStatus(500);
        $this->assertSame('2',$this->next());
        DB::statement('ALTER TABLE audit_log DROP CONSTRAINT number_test_failure');
        $this->create()->assertOk()->assertJsonPath('record.projectNo','2');
    }

    public function test_import_numbers_are_assigned_only_to_rows_actually_saved(): void
    {
        $this->project('1');
        $this->import([
            ['title'=>'Auto A','projectNo'=>'70','autoProjectNumber'=>true],
            ['title'=>'Auto B','projectNo'=>'99','autoProjectNumber'=>true],
            ['title'=>'Auto C'],
        ])->assertOk();
        $this->assertSame(['1','2','3','4'],DB::table('projects')->orderBy('id')->pluck('project_no')->all());
        foreach(DB::table('projects')->get() as $row)$this->assertArrayNotHasKey('autoProjectNumber',json_decode($row->data,true));
        $this->assertSame('5',$this->next());
    }

    public function test_explicit_import_numbers_are_preserved_without_colliding_with_automatic_rows(): void
    {
        $this->project('1');
        $this->import([
            ['title'=>'Auto row','autoProjectNumber'=>true],
            ['title'=>'Number from spreadsheet','projectNo'=>'2'],
        ])->assertOk();
        $this->assertSame(['1','3','2'],DB::table('projects')->orderBy('id')->pluck('project_no')->all());
        $this->assertSame('4',$this->next());
    }

    public function test_overwriting_an_imported_project_keeps_its_permanent_number(): void
    {
        $id=$this->project('7');
        $this->import([['id'=>$id,'title'=>'Updated project','projectNo'=>'999','updatedAt'=>'2026-10-01T00:00:00Z']])->assertOk();
        $saved=json_decode(DB::table('projects')->where('id',$id)->value('data'),true);
        $this->assertSame('7',$saved['projectNo']);
        $this->assertSame('8',$this->next());
    }

    public function test_import_rollback_restores_the_next_number_from_remaining_projects(): void
    {
        $id=$this->project('4');
        $this->import([['id'=>$id,'title'=>'Changed title','updatedAt'=>'2026-10-01T00:00:00Z']],'rollback')->assertOk();
        DB::table('project_number_sequences')->update(['next_number'=>500]);
        $this->api(['action'=>'rollbackImport','batchId'=>'project-number-test-rollback'])->assertOk();
        $this->assertSame('5',$this->next());
        $this->create()->assertOk()->assertJsonPath('record.projectNo','5');
    }
}
