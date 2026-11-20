<?php
namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDirectDeletionTest extends TestCase
{
    private ?string $schema = null;
    private int $userId = 2;
    private int $projectId;
    private int $historyId;
    protected function setUp(): void {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL direct deletion test.');
        if (!str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema='admin_direct_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"'); DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        foreach([1=>'staff',2=>'admin',3=>'admin',4=>'superadmin'] as $id=>$role) {
            DB::table('users')->insert(['id'=>$id,'username'=>'direct'.$id,'full_name'=>'Direct '.$id,'role'=>$role,'active'=>true,'can_delete'=>true,'can_delete_all'=>true,'password_hash'=>password_hash('direct-fixture-password', PASSWORD_BCRYPT)]);
            DB::table('tracker_account_sessions')->insert(['user_id'=>$id,'token_hash'=>hash('sha256','direct-account-'.$id),'started_at'=>time(),'last_activity_at'=>time()]);
        }
        $this->projectId=$this->project('Admin direct project');
        $this->historyId=DB::table('history')->insertGetId(['project_id'=>$this->projectId,'data'=>json_encode(['projectId'=>$this->projectId,'action'=>'Created']),'updated_at'=>now()]);
    }
    protected function tearDown(): void {
        if($this->schema) { DB::statement('SET search_path TO public'); DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE'); }
        parent::tearDown();
    }
    private function project(string $title): int {
        $data=['title'=>$title,'status'=>'Pending','approvedBudget'=>'1000','updatedAt'=>'2026-10-29T00:00:00Z'];
        return DB::table('projects')->insertGetId(['data'=>json_encode($data)]+TrackerDataController::projectColumns($data));
    }
    private function authenticated(): self {
        $this->withSession(['tracker_user'=>['id'=>$this->userId],'tracker_csrf'=>'direct-csrf','tracker_session_token'=>'direct-account-'.$this->userId]);
        app('session')->save();
        return $this->withCredentials()->withCookie(config('session.cookie'),app('session')->getId())->withHeader('X-CSRF-Token','direct-csrf');
    }
    private function api(array $body) { return $this->authenticated()->postJson('/api/deletion-requests.php',$body); }
    private function target(array $replace=[]): array { return $replace+['targetType'=>'project','id'=>$this->projectId]; }
    private function prepare(array $target=[],string $reason='Reviewed duplicate'): string {
        $target=$this->target($target);
        $preview=$this->api(['action'=>'previewDirect']+$target)->assertOk()->json('selection');
        return $this->api(['action'=>'prepareDirect','expectedVersion'=>$preview['version'],'reason'=>$reason]+$target)->assertOk()->json('confirmationToken');
    }
    private function confirm(string $token,array $replace=[]) {
        return $this->api($replace+['action'=>'confirmDirect','confirmationToken'=>$token,'confirmed'=>true,'current_password'=>'direct-fixture-password']);
    }
    public function test_admin_review_and_final_confirmation_delete_without_a_pending_request(): void {
        $directory=config('tracker.attachments').'/'.$this->projectId; if(!is_dir($directory)) mkdir($directory,0750,true);
        file_put_contents($directory.'/direct-fixture.txt','admin fixture');
        $data=json_decode(DB::table('projects')->where('id',$this->projectId)->value('data'),true);
        $data['attachments']=[['storedName'=>'direct-fixture.txt']]; DB::table('projects')->where('id',$this->projectId)->update(['data'=>json_encode($data)]);
        $preview=$this->api(['action'=>'previewDirect']+$this->target())->assertOk()->assertJsonPath('selection.direct',true)->assertJsonPath('selection.title','Admin direct project');
        $token=$this->api(['action'=>'prepareDirect','expectedVersion'=>$preview->json('selection.version')]+$this->target())->assertOk()->json('confirmationToken');
        $this->assertDatabaseCount('projects',1);$this->assertDatabaseCount('history',1);$this->assertDatabaseCount('deletion_requests',0);$this->assertFileExists($directory.'/direct-fixture.txt');
        $this->confirm($token)->assertOk();
        $this->assertDatabaseCount('projects',0);$this->assertDatabaseCount('history',0);$this->assertDatabaseCount('deletion_requests',0);$this->assertFileDoesNotExist($directory.'/direct-fixture.txt');
        $audit=DB::table('audit_log')->where('action','project.deleted')->first();$metadata=json_decode($audit->metadata,true);
        $this->assertSame(2,(int)$audit->actor_id);$this->assertSame('admin_direct_two_step',$metadata['method']);
        $this->assertNotEmpty($metadata['reviewedAt']);$this->assertNotEmpty($metadata['confirmedAt']);
    }
    public function test_staff_superadmin_anonymous_and_bad_csrf_cannot_enter_direct_deletion(): void {
        $this->postJson('/api/deletion-requests.php',['action'=>'previewDirect']+$this->target())->assertUnauthorized();
        $this->authenticated()->withHeader('X-CSRF-Token','wrong')->postJson('/api/deletion-requests.php',['action'=>'previewDirect']+$this->target())->assertStatus(419);
        foreach([1,4] as $id) { $this->userId=$id;
            foreach(['previewDirect','prepareDirect','confirmDirect','cancelDirect'] as $action) $this->api(['action'=>$action]+$this->target())->assertForbidden();
        }
        $this->assertDatabaseCount('projects',1);
    }
    public function test_confirmation_cannot_skip_preparation_acknowledgement_or_replay(): void {
        $this->confirm(str_repeat('a',64))->assertConflict();
        $token=$this->prepare();$this->confirm(str_repeat('b',64))->assertConflict();
        $this->confirm($token,['confirmed'=>false])->assertUnprocessable();$this->assertDatabaseCount('projects',1);
        $this->confirm($token)->assertOk();$this->confirm($token)->assertConflict();
        $this->assertSame(1,DB::table('audit_log')->where('action','project.deleted')->count());
    }
    public function test_changed_project_blocks_preparation_and_changed_history_blocks_confirmation(): void {
        $preview=$this->api(['action'=>'previewDirect']+$this->target())->assertOk()->json('selection');
        DB::table('projects')->where('id',$this->projectId)->update(['data'=>json_encode(['title'=>'Changed'])]);
        $this->api(['action'=>'prepareDirect','expectedVersion'=>$preview['version']]+$this->target())->assertConflict();
        $token=$this->prepare();DB::table('history')->where('id',$this->historyId)->update(['data'=>json_encode(['action'=>'Changed history'])]);
        $this->confirm($token)->assertConflict();$this->assertDatabaseCount('projects',1);$this->assertDatabaseCount('history',1);
    }
    public function test_cancelling_the_confirmation_keeps_records_and_invalidates_its_token(): void {
        $token=$this->prepare();$this->api(['action'=>'cancelDirect','confirmationToken'=>$token])->assertOk();
        $this->confirm($token)->assertConflict();$this->assertDatabaseCount('projects',1);$this->assertDatabaseCount('deletion_requests',0);
    }
    public function test_expired_or_other_admin_confirmation_cannot_delete(): void {
        $token=$this->prepare();$this->userId=3;$this->confirm($token)->assertConflict();$this->userId=2;
        $this->travel(11)->minutes();
        try { $this->confirm($token)->assertConflict();$this->assertDatabaseCount('projects',1); }
        finally { $this->travelBack(); }
    }
    public function test_revoked_admin_role_cannot_finish_direct_deletion(): void {
        $token=$this->prepare();DB::table('users')->where('id',2)->update(['role'=>'staff']);
        $this->confirm($token)->assertForbidden();$this->assertDatabaseCount('projects',1);
    }
    public function test_bulk_deletion_excludes_projects_added_after_review_and_logs_the_note(): void {
        $second=$this->project('Second reviewed project');$token=$this->prepare(['all'=>true],'Bulk admin note');$later=$this->project('Later project');
        $this->confirm($token,['id'=>$later,'targetType'=>'history'])->assertOk();
        $this->assertDatabaseCount('projects',1);$this->assertDatabaseHas('projects',['id'=>$later]);
        $this->assertSame(2,DB::table('audit_log')->where('action','project.deleted')->count());
        foreach(DB::table('audit_log')->where('action','project.deleted')->get() as $event) $this->assertSame('Bulk admin note',json_decode($event->metadata,true)['reason']);
        $this->assertDatabaseCount('deletion_requests',0);
    }
    public function test_history_deletion_keeps_the_project_and_other_history(): void {
        $other=DB::table('history')->insertGetId(['project_id'=>$this->projectId,'data'=>'{"action":"Other entry"}','updated_at'=>now()]);
        $token=$this->prepare(['targetType'=>'history','id'=>$this->historyId]);$this->confirm($token)->assertOk();
        $this->assertDatabaseCount('projects',1);$this->assertDatabaseCount('history',1);$this->assertDatabaseHas('history',['id'=>$other]);
        $this->assertSame(1,DB::table('audit_log')->where('action','history.deleted')->count());
    }

    public function test_bulk_deletion_closes_pending_and_approved_requests_after_confirmation(): void
    {
        $archived = $this->project('Archived project');
        DB::table('projects')->where('id', $archived)->update(['archived_at' => now()]);
        $archivedLog = DB::table('history')->insertGetId([
            'project_id' => $archived, 'data' => '{"action":"Archived"}', 'updated_at' => now(),
        ]);

        $this->userId = 1;
        $pending = $this->api(['action' => 'create', 'reason' => 'Remove project'] + $this->target())
            ->assertStatus(202)->json('requestId');
        $approved = $this->api(['action' => 'create', 'reason' => 'Remove archive log', 'targetType' => 'history', 'id' => $archivedLog])
            ->assertStatus(202)->json('requestId');

        $this->userId = 2;
        $version = $this->authenticated()->getJson('/api/deletion-requests.php?id='.$approved)
            ->assertOk()->json('request.version');
        $oldToken = $this->api(['action' => 'review', 'id' => $approved, 'decision' => 'approve', 'expectedVersion' => $version])
            ->assertOk()->json('confirmationToken');
        $token = $this->prepare(['all' => true]);
        $this->assertDatabaseHas('deletion_requests', ['id' => $pending, 'status' => 'pending']);
        $this->assertDatabaseHas('deletion_requests', ['id' => $approved, 'status' => 'approved']);
        $this->assertDatabaseCount('projects', 2);

        $this->confirm($token)->assertOk();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('history', 0);
        $this->assertDatabaseCount('deletion_requests', 2);
        foreach ([$pending, $approved] as $id) {
            $this->assertDatabaseHas('deletion_requests', ['id' => $id, 'status' => 'deleted', 'confirmation_hash' => null]);
            $this->assertDatabaseHas('deletion_request_targets', ['request_id' => $id, 'active_key' => null]);
            $this->assertDatabaseHas('audit_log', ['action' => 'deletion.confirmed', 'entity_id' => $id, 'actor_id' => 2]);
        }
        $this->api(['action' => 'confirm', 'id' => $approved, 'confirmationToken' => $oldToken, 'confirmed' => true, 'current_password' => 'direct-fixture-password'])->assertConflict();
    }

    public function test_bulk_deletion_closes_partially_overlapping_request_without_deleting_later_project(): void
    {
        $token = $this->prepare(['all' => true]);
        $later = $this->project('Project added after review');
        $this->userId = 3;
        $requestId = $this->api(['action' => 'create', 'reason' => 'Remove both', 'targetType' => 'project', 'all' => true])
            ->assertStatus(202)->json('requestId');

        $this->userId = 2;
        $this->confirm($token)->assertOk();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('projects', ['id' => $later]);
        $this->assertDatabaseHas('deletion_requests', ['id' => $requestId, 'status' => 'cancelled', 'confirmation_hash' => null]);
        $this->assertFalse(DB::table('deletion_request_targets')->where('request_id', $requestId)->whereNotNull('active_key')->exists());

        $this->userId = 1;
        $this->api(['action' => 'create', 'reason' => 'Review the remaining project', 'targetType' => 'project', 'id' => $later])->assertStatus(202);
    }

    public function test_a_new_staff_request_blocks_direct_confirmation_and_is_preserved(): void {
        $token=$this->prepare();$this->userId=1;
        $requestId=$this->api(['action'=>'create','reason'=>'Staff reason']+$this->target())->assertStatus(202)->json('requestId');
        $this->userId=2;$this->confirm($token)->assertConflict();
        $this->api(['action'=>'previewDirect']+$this->target())->assertConflict();
        $this->assertDatabaseCount('projects',1);$this->assertDatabaseHas('deletion_requests',['id'=>$requestId,'status'=>'pending']);
    }
    public function test_repreparing_invalidates_old_tokens_and_rejects_invalid_input(): void {
        $first=$this->prepare();$second=$this->prepare();$this->confirm($first)->assertConflict();
        $this->api(['action'=>'prepareDirect','expectedVersion'=>str_repeat('a',64),'reason'=>str_repeat('x',2001)]+$this->target())->assertUnprocessable();
        $this->api(['action'=>'previewDirect','targetType'=>'users','id'=>1])->assertUnprocessable();
        $this->api(['action'=>'prepareDirect']+$this->target())->assertUnprocessable();
        $this->api(['action'=>'cancelDirect','confirmationToken'=>$first])->assertOk();$this->confirm($second)->assertOk();
    }
}
