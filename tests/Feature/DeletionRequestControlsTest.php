<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeletionRequestControlsTest extends TestCase
{
    private ?string $schema = null;
    private array $session;
    private int $projectId;
    private int $historyId;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
            $this->schema = 'deletion_'.bin2hex(random_bytes(6));
            DB::statement('CREATE SCHEMA "'.$this->schema.'"');
            DB::statement('SET search_path TO "'.$this->schema.'"');
            DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        } else $this->sqliteSchema();
        if (! Schema::hasTable('deletion_requests')) (require database_path('migrations/2026_10_29_000001_create_deletion_requests.php'))->up();
        (require database_path('migrations/2026_10_30_000001_add_history_clearing_and_password_viewing.php'))->up();
        (require database_path('migrations/2026_10_30_000002_create_import_staging_chunks.php'))->up();
        foreach ([1 => 'staff', 2 => 'admin', 3 => 'staff', 4 => 'superadmin', 5 => 'admin'] as $id => $role) {
            DB::table('users')->insert(['id' => $id, 'username' => 'deletion'.$id, 'full_name' => 'Deletion <b>User</b> '.$id,
                'role' => $role, 'active' => true, 'can_delete' => $id === 1, 'can_delete_all' => $id === 1,
                'can_edit' => $id === 1, 'can_import' => $id === 1, 'password_hash' => password_hash('fixture-password', PASSWORD_BCRYPT)]);
            DB::table('tracker_account_sessions')->insert(['user_id' => $id, 'token_hash' => hash('sha256', 'account-'.$id), 'started_at' => time(), 'last_activity_at' => time()]);
        }
        $this->projectId = $this->project('Approval project');
        $this->historyId = DB::table('history')->insertGetId(['project_id' => $this->projectId, 'data' => json_encode(['action' => 'Edited', 'projectId' => $this->projectId]), 'updated_at' => now()]);
        $this->asUser(1);
    }

    protected function tearDown(): void
    {
        if ($this->schema) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    private function sqliteSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id(); foreach (['username', 'full_name', 'password_hash', 'role'] as $name) $t->string($name);
            $t->boolean('active')->default(true);
            foreach (['can_create','can_edit','can_import','can_export','can_delete','can_delete_all','can_resolve_alerts','can_archive'] as $name) $t->boolean($name)->default(false);
        });
        Schema::create('login_attempts', function (Blueprint $t) { $t->string('attempt_key')->primary(); $t->integer('failed_count')->default(0); $t->timestamp('first_failed_at'); $t->timestamp('locked_until')->nullable(); $t->timestamp('updated_at'); });
        Schema::create('tracker_account_sessions', function (Blueprint $t) { $t->unsignedBigInteger('user_id')->primary(); $t->string('token_hash'); $t->bigInteger('started_at'); $t->bigInteger('last_activity_at'); });
        Schema::create('projects', function (Blueprint $t) {
            $t->id(); $t->json('data');
            foreach (['project_no','title','project_status','bidding_date','completion_date','noa_date','ntp_date','winning_bidder','source_of_fund','pr_number','approved_budget','bid_amount'] as $name) $t->string($name)->nullable();
            $t->bigInteger('updated_by')->nullable(); $t->timestamp('updated_at')->useCurrent(); $t->timestamp('archived_at')->nullable();
        });
        Schema::create('history', function (Blueprint $t) { $t->id(); $t->json('data'); $t->bigInteger('project_id')->nullable(); $t->bigInteger('updated_by')->nullable(); $t->timestamp('updated_at')->useCurrent(); $t->timestamp('event_at')->nullable(); });
        Schema::create('audit_log', function (Blueprint $t) { $t->id(); $t->bigInteger('actor_id')->nullable(); foreach (['actor_username','action','entity_type','summary'] as $name) $t->string($name); $t->bigInteger('entity_id')->nullable(); $t->json('metadata')->nullable(); $t->timestamp('created_at'); });
        Schema::create('project_editing_presence', function (Blueprint $t) { $t->id(); $t->bigInteger('user_id'); $t->bigInteger('project_id'); $t->string('session_key')->nullable(); $t->timestamp('ended_at')->nullable(); });
        Schema::create('application_settings', function (Blueprint $t) { $t->string('setting_key')->primary(); $t->json('setting_value'); });
        Schema::create('project_number_sequences', function (Blueprint $t) { $t->string('sequence_name')->primary(); $t->bigInteger('next_number'); });
        DB::table('project_number_sequences')->insert(['sequence_name' => 'projects', 'next_number' => 2]);
        Schema::create('import_jobs', function (Blueprint $t) { $t->string('batch_id')->primary(); $t->bigInteger('actor_id'); $t->string('status'); $t->timestamp('rolled_back_at')->nullable(); });
        Schema::create('import_job_changes', function (Blueprint $t) { $t->id(); $t->string('batch_id'); $t->string('change_type'); $t->bigInteger('project_id'); });
    }

    private function project(string $title): int
    {
        $data = ['title' => $title, 'status' => 'Pending', 'approvedBudget' => '1000', 'updatedAt' => '2026-10-29T00:00:00Z'];
        return DB::table('projects')->insertGetId(['data' => json_encode($data)] + TrackerDataController::projectColumns($data));
    }

    private function asUser(int $id): void
    {
        $this->session = ['tracker_user' => ['id' => $id], 'tracker_csrf' => 'deletion-token', 'tracker_session_token' => 'account-'.$id];
    }

    private function authenticated(): self
    {
        $this->withSession($this->session);
        app('session')->save();
        return $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId())->withHeader('X-CSRF-Token', 'deletion-token');
    }

    private function detailUrl(int $id): string
    {
        $owner = (int) DB::table('deletion_requests')->where('id', $id)->value('requested_by');
        $userId = (int) $this->session['tracker_user']['id'];
        $scope = $owner !== $userId && DB::table('users')->where('id', $userId)->value('role') === 'admin' ? 'review' : 'mine';
        return '/api/deletion-requests.php?id='.$id.'&scope='.$scope;
    }

    private function api(array $data)
    {
        if (in_array($data['action'] ?? '', ['review', 'editReason', 'cancel'], true) && isset($data['id']) && ! array_key_exists('expectedVersion', $data)) {
            $data['expectedVersion'] = $this->authenticated()->getJson($this->detailUrl($data['id']))->json('request.version') ?? str_repeat('0', 64);
        }
        return $this->authenticated()->postJson('/api/deletion-requests.php', $data);
    }

    private function requestDeletion(array $data = []): int
    {
        return $this->api($data + ['action' => 'create', 'targetType' => 'project', 'id' => $this->projectId, 'reason' => 'Duplicate <script>record</script>'])->assertStatus(202)->json('requestId');
    }

    private function approve(int $id): string
    {
        return $this->api(['action' => 'review', 'id' => $id, 'decision' => 'approve', 'note' => 'Reviewed duplicate'])->assertOk()->json('confirmationToken');
    }

    private function confirm(int $id, string $token)
    {
        return $this->api(['action' => 'confirm', 'id' => $id, 'confirmationToken' => $token, 'confirmed' => true, 'current_password' => 'fixture-password']);
    }

    public function test_trash_lookup_reopens_own_request_without_disclosing_someone_elses_request(): void
    {
        $url = '/api/deletion-requests.php?lookup=1&targetType=project&all=0&targetId='.$this->projectId;
        $this->authenticated()->getJson($url)->assertOk()->assertJsonPath('request', null)->assertJsonPath('blocked', false);
        $id = $this->requestDeletion();
        $this->authenticated()->getJson($url)->assertOk()->assertJsonPath('request.id', $id)->assertJsonPath('request.isOwner', true)->assertJsonPath('request.reason', 'Duplicate <script>record</script>');
        $this->assertDatabaseCount('deletion_requests', 1);
        $this->asUser(3);
        $result = $this->authenticated()->getJson($url)->assertOk()->assertJsonPath('request', null)->assertJsonPath('blocked', true)->json();
        $this->assertArrayNotHasKey('requester', $result); $this->assertArrayNotHasKey('reason', $result); $this->assertArrayNotHasKey('requestId', $result);
    }

    public function test_owner_edits_reason_with_validation_and_audit_history(): void
    {
        $id = $this->requestDeletion();
        $this->api(['action'=>'editReason','id'=>$id,'reason'=>'  Corrected reason  '])->assertOk();
        $this->authenticated()->getJson($this->detailUrl($id))->assertJsonPath('request.reason','Corrected reason')->assertJsonPath('request.stage','pending');
        $this->assertNotNull($this->authenticated()->getJson($this->detailUrl($id))->json('request.reasonUpdatedAt'));
        $metadata = json_decode(DB::table('audit_log')->where('action','deletion.reason_updated')->value('metadata'),true);
        $this->assertSame('Duplicate <script>record</script>',$metadata['previousReason']);
        $this->assertSame('Corrected reason',$metadata['reason']);
        $this->api(['action'=>'editReason','id'=>$id,'reason'=>'   '])->assertUnprocessable();
        $this->api(['action'=>'editReason','id'=>$id,'reason'=>str_repeat('x',2001)])->assertUnprocessable();
        $this->asUser(2); $this->api(['action'=>'editReason','id'=>$id,'reason'=>'Admin cannot change another requester reason'])->assertNotFound();
        $this->asUser(3); $this->api(['action'=>'cancel','id'=>$id])->assertNotFound();
        $this->assertDatabaseCount('projects',1); $this->assertDatabaseCount('history',1);
    }

    public function test_cancel_keeps_records_releases_targets_and_preserves_cancelled_history(): void
    {
        $id=$this->requestDeletion();
        $this->api(['action'=>'cancel','id'=>$id])->assertOk();
        $this->assertDatabaseCount('projects',1);$this->assertDatabaseCount('history',1);
        $this->assertSame(0,DB::table('deletion_request_targets')->whereNotNull('active_key')->count());
        $detail=$this->authenticated()->getJson($this->detailUrl($id))->assertJsonPath('request.status','cancelled')->json('request');
        $this->assertNotNull($detail['cancelledAt']);
        $this->authenticated()->getJson('/api/deletion-requests.php?status=cancelled')->assertJsonCount(1,'requests')->assertJsonPath('pendingCount',0);
        $this->api(['action'=>'cancel','id'=>$id])->assertConflict();
        $this->assertNotSame($id,$this->requestDeletion());
        $this->assertSame(1,DB::table('audit_log')->where('action','deletion.cancelled')->count());
    }

    public function test_edit_after_approval_invalidates_old_confirmation_and_requires_fresh_review(): void
    {
        $id=$this->requestDeletion();$this->asUser(2);$token=$this->approve($id);
        $oldVersion=$this->authenticated()->getJson($this->detailUrl($id))->json('request.version');
        $this->asUser(1);$this->api(['action'=>'editReason','id'=>$id,'reason'=>'Updated after review'])->assertOk();
        $this->assertDatabaseHas('deletion_requests',['id'=>$id,'status'=>'pending','confirmation_hash'=>null,'reviewer'=>null]);
        $this->asUser(2);$this->confirm($id,$token)->assertConflict();
        $this->api(['action'=>'review','id'=>$id,'decision'=>'approve','expectedVersion'=>$oldVersion])->assertConflict();
        $newToken=$this->approve($id);$this->confirm($id,$newToken)->assertOk();
        $this->asUser(1);$this->api(['action'=>'cancel','id'=>$id])->assertConflict();$this->api(['action'=>'editReason','id'=>$id,'reason'=>'Too late'])->assertConflict();
    }

    public function test_cancel_after_approval_prevents_final_deletion_even_with_the_old_token(): void
    {
        $id=$this->requestDeletion();$this->asUser(2);$token=$this->approve($id);
        $this->asUser(1);$this->api(['action'=>'cancel','id'=>$id])->assertOk();
        $this->asUser(2);$this->confirm($id,$token)->assertConflict();
        $this->api(['action'=>'prepareConfirmation','id'=>$id])->assertConflict();
        $this->assertDatabaseCount('projects',1);$this->assertDatabaseCount('history',1);
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=review&status=pending')->assertJsonCount(0,'requests')->assertJsonPath('pendingCount',0);
    }

    public function test_staff_lists_and_explicit_personal_scope_are_private_while_admin_queue_includes_all(): void
    {
        $staff=$this->requestDeletion();$this->asUser(2);$admin=$this->requestDeletion(['id'=>$this->project('Admin request')]);
        DB::table('users')->where('id',3)->update(['can_delete'=>true]);$this->asUser(3);$other=$this->requestDeletion(['id'=>$this->project('Other staff request')]);
        $this->asUser(2);
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=mine')->assertJsonCount(1,'requests')->assertJsonPath('requests.0.id',$admin)->assertJsonPath('pendingCount',1);
        $this->authenticated()->getJson('/api/deletion-requests.php?id='.$staff.'&scope=mine')->assertNotFound();
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=review')->assertJsonCount(3,'requests')->assertJsonPath('pendingCount',3);
        $this->api(['action'=>'cancel','id'=>$staff])->assertNotFound();
        $this->asUser(1);$this->authenticated()->getJson('/api/deletion-requests.php')->assertJsonCount(1,'requests')->assertJsonPath('requests.0.id',$staff);
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=review')->assertForbidden();
        $this->authenticated()->getJson('/api/deletion-requests.php?id='.$other)->assertNotFound();
        $this->asUser(5);$this->authenticated()->getJson('/api/deletion-requests.php?scope=mine')->assertJsonCount(0,'requests')->assertJsonPath('pendingCount',0);
    }

    public function test_stale_reason_or_review_cannot_overwrite_new_changes_even_after_reason_is_restored(): void
    {
        $id=$this->requestDeletion();$original=$this->authenticated()->getJson($this->detailUrl($id))->json('request');
        $this->api(['action'=>'editReason','id'=>$id,'reason'=>'Second reason'])->assertOk();
        $this->api(['action'=>'editReason','id'=>$id,'reason'=>$original['reason']])->assertOk();
        $this->api(['action'=>'cancel','id'=>$id,'expectedVersion'=>$original['version']])->assertConflict();
        $this->asUser(2);$this->api(['action'=>'review','id'=>$id,'decision'=>'approve','expectedVersion'=>$original['version']])->assertConflict();
        $this->assertDatabaseHas('deletion_requests',['id'=>$id,'status'=>'pending']);
    }

    public function test_project_lookup_opens_an_owned_bulk_request_and_cancel_releases_all_projects(): void
    {
        $other=$this->project('Second bulk target');
        // Keep coverage for an owned bulk request created before Staff Delete All was removed.
        $this->asUser(2);$id=$this->requestDeletion(['all'=>true]);
        DB::table('deletion_requests')->where('id',$id)->update(['requested_by'=>1]);$this->asUser(1);
        foreach([$this->projectId,$other]as$target)$this->authenticated()->getJson('/api/deletion-requests.php?lookup=1&targetType=project&all=0&targetId='.$target)->assertJsonPath('request.id',$id)->assertJsonPath('request.targetCount',2);
        $this->authenticated()->getJson('/api/deletion-requests.php?lookup=1&targetType=project&all=1')->assertJsonPath('request.id',$id);
        $this->api(['action'=>'cancel','id'=>$id])->assertOk();$this->assertDatabaseCount('projects',2);
        $this->authenticated()->getJson('/api/deletion-requests.php?lookup=1&targetType=project&all=0&targetId='.$other)->assertJsonPath('request',null)->assertJsonPath('blocked',false);
        $this->requestDeletion(['id'=>$other]);
    }

    public function test_owner_can_withdraw_after_permission_revocation_and_rejected_requests_are_final(): void
    {
        $id=$this->requestDeletion();DB::table('users')->where('id',1)->update(['can_delete'=>false,'can_edit'=>false]);
        $this->api(['action'=>'editReason','id'=>$id,'reason'=>'Withdraw this request'])->assertOk();$this->api(['action'=>'cancel','id'=>$id])->assertOk();
        $this->asUser(2);$id=$this->requestDeletion();$this->api(['action'=>'review','id'=>$id,'decision'=>'reject'])->assertOk();
        $this->api(['action'=>'editReason','id'=>$id,'reason'=>'Cannot change a rejected request'])->assertConflict();$this->api(['action'=>'cancel','id'=>$id])->assertConflict();
    }

}
