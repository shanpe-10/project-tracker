<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeletionApprovalTest extends TestCase
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

    public function test_staff_request_preserves_projects_history_and_attachments_until_two_admin_steps(): void
    {
        $directory = config('tracker.attachments').'/'.$this->projectId;
        if (! is_dir($directory)) mkdir($directory, 0750, true);
        file_put_contents($directory.'/fixture.txt', 'keep until confirmed');
        $data = json_decode(DB::table('projects')->where('id', $this->projectId)->value('data'), true);
        $data['attachments'] = [['storedName' => 'fixture.txt', 'name' => 'fixture.txt']];
        DB::table('projects')->where('id', $this->projectId)->update(['data' => json_encode($data)]);
        $id = $this->requestDeletion();
        $this->assertDatabaseCount('projects', 1); $this->assertDatabaseCount('history', 1); $this->assertFileExists($directory.'/fixture.txt');
        $this->asUser(2); $token = $this->approve($id);
        $this->assertDatabaseCount('projects', 1); $this->assertDatabaseCount('history', 1); $this->assertFileExists($directory.'/fixture.txt');
        $this->authenticated()->getJson($this->detailUrl($id))->assertJsonPath('request.status', 'pending')->assertJsonPath('request.stage', 'approved');
        $this->confirm($id, $token)->assertOk();
        $this->assertDatabaseCount('projects', 0); $this->assertDatabaseCount('history', 0); $this->assertFileDoesNotExist($directory.'/fixture.txt');
        $this->asUser(1);
        $this->authenticated()->getJson($this->detailUrl($id))->assertJsonPath('request.status', 'deleted')->assertJsonPath('request.deletedBy.id', 2)->assertJsonPath('request.requester.id', 1);
        $this->assertSame(['deletion.requested','deletion.approved','deletion.confirmed','project.deleted'], DB::table('audit_log')->orderBy('id')->pluck('action')->all());
        $this->assertNotNull(DB::table('deletion_requests')->where('id', $id)->value('deleted_at'));
    }

    public function test_reason_permission_csrf_and_identity_are_server_enforced(): void
    {
        $this->getJson('/api/deletion-requests.php')->assertUnauthorized();
        $this->authenticated()->withHeader('X-CSRF-Token', 'wrong')->postJson('/api/deletion-requests.php', ['action' => 'create'])->assertStatus(419);
        $this->api(['action' => 'create', 'targetType' => 'project', 'id' => $this->projectId, 'reason' => '   '])->assertUnprocessable();
        $this->api(['action' => 'create', 'targetType' => 'project', 'id' => $this->projectId, 'reason' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->asUser(3); $this->api(['action' => 'create', 'targetType' => 'project', 'id' => $this->projectId, 'reason' => 'No permission'])->assertForbidden();
        $this->asUser(4); $this->authenticated()->getJson('/api/deletion-requests.php')->assertForbidden();
        $this->asUser(1); $id = $this->requestDeletion(['requester' => ['id' => 2], 'status' => 'deleted']);
        $this->assertSame(1, (int) DB::table('deletion_requests')->where('id', $id)->value('requested_by'));
        DB::table('users')->where('id', 1)->update(['can_delete' => false]);
        $this->api(['action' => 'create', 'targetType' => 'project', 'id' => $this->projectId, 'reason' => 'Revoked permission'])->assertForbidden();
    }

    public function test_staff_cannot_approve_prepare_or_confirm_and_cannot_view_another_staff_request(): void
    {
        $id = $this->requestDeletion();
        $this->api(['action' => 'review', 'id' => $id, 'decision' => 'approve'])->assertForbidden();
        $this->api(['action' => 'review', 'id' => $id, 'decision' => 'reject'])->assertForbidden();
        $this->api(['action' => 'prepareConfirmation', 'id' => $id])->assertForbidden();
        $this->confirm($id, str_repeat('a', 64))->assertForbidden();
        $this->asUser(3);
        $this->authenticated()->getJson('/api/deletion-requests.php')->assertJsonCount(0, 'requests');
        $this->authenticated()->getJson($this->detailUrl($id))->assertNotFound();
        $this->asUser(2); $this->authenticated()->getJson('/api/deletion-requests.php?scope=mine')->assertJsonCount(0, 'requests');
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=review')->assertJsonCount(1, 'requests');
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_rejected_request_preserves_records_and_allows_a_new_request(): void
    {
        $id = $this->requestDeletion(); $this->asUser(2);
        $this->api(['action' => 'review', 'id' => $id, 'decision' => 'reject', 'note' => 'Still needed'])->assertOk();
        $this->confirm($id, str_repeat('a', 64))->assertConflict();
        $this->assertDatabaseCount('projects', 1); $this->assertDatabaseCount('history', 1);
        $this->asUser(1);
        $this->authenticated()->getJson('/api/deletion-requests.php?status=rejected')->assertJsonPath('requests.0.status', 'rejected')->assertJsonPath('requests.0.decisionNote', 'Still needed');
        $this->assertNotSame($id, $this->requestDeletion());
    }

    public function test_final_confirmation_cannot_skip_review_use_a_wrong_token_or_be_replayed(): void
    {
        $id = $this->requestDeletion(); $this->asUser(2);
        $this->confirm($id, str_repeat('a', 64))->assertConflict();
        $this->api(['action' => 'prepareConfirmation', 'id' => $id])->assertConflict();
        $token = $this->approve($id);
        $this->confirm($id, str_repeat('b', 64))->assertConflict();
        $this->api(['action' => 'confirm', 'id' => $id, 'confirmationToken' => $token, 'confirmed' => false])->assertUnprocessable();
        $this->assertDatabaseCount('projects', 1);
        $newToken = $this->api(['action' => 'prepareConfirmation', 'id' => $id])->assertOk()->json('confirmationToken');
        $this->confirm($id, $token)->assertConflict();
        $this->asUser(1); $this->confirm($id, $newToken)->assertForbidden();
        $this->asUser(2); $this->confirm($id, $newToken)->assertOk(); $this->confirm($id, $newToken)->assertConflict();
        $this->assertSame(1, DB::table('audit_log')->where('action', 'deletion.confirmed')->count());
    }

    public function test_changed_or_missing_records_block_approval_and_final_deletion(): void
    {
        $id = $this->requestDeletion(); $this->asUser(2);
        DB::table('projects')->where('id', $this->projectId)->update(['data' => json_encode(['title' => 'Changed before review'])]);
        $this->api(['action' => 'review', 'id' => $id, 'decision' => 'approve'])->assertConflict();
        $this->api(['action' => 'review', 'id' => $id, 'decision' => 'reject'])->assertOk();
        $this->asUser(1); $id = $this->requestDeletion(); $this->asUser(2); $token = $this->approve($id);
        DB::table('history')->insert(['project_id' => $this->projectId, 'data' => '{}', 'updated_at' => now()]);
        $this->confirm($id, $token)->assertConflict(); $this->assertDatabaseCount('projects', 1);
        $this->api(['action' => 'review', 'id' => $id, 'decision' => 'reject'])->assertOk();
        $this->asUser(1); $id = $this->requestDeletion(); DB::table('projects')->where('id', $this->projectId)->delete();
        $this->asUser(2); $this->api(['action' => 'review', 'id' => $id, 'decision' => 'approve'])->assertConflict();
    }

    public function test_legacy_delete_and_bulk_endpoints_create_requests_and_bulk_excludes_later_projects(): void
    {
        $this->authenticated()->postJson('/api/data.php', ['action' => 'deleteProject', 'id' => $this->projectId])->assertUnprocessable();
        $this->authenticated()->postJson('/api/data.php', ['action' => 'clearStore', 'store' => 'projects', 'reason' => 'Old records'])->assertForbidden();
        $this->asUser(2);
        $id = $this->authenticated()->postJson('/api/data.php', ['action' => 'clearStore', 'store' => 'projects', 'reason' => 'Old records'])->assertStatus(202)->json('requestId');
        $later = $this->project('Created after request');
        $this->assertDatabaseCount('projects', 2);
        $this->asUser(2); $token = $this->approve($id); $this->confirm($id, $token)->assertOk();
        $this->assertDatabaseHas('projects', ['id' => $later]); $this->assertDatabaseCount('projects', 1);
        $this->asUser(1);
        $next = $this->authenticated()->postJson('/api/data.php', ['action' => 'deleteProject', 'id' => $later, 'reason' => 'Another request'])->assertStatus(202)->json('requestId');
        $this->assertNotSame($id, $next); $this->assertDatabaseCount('projects', 1);
    }

    public function test_history_deletion_requires_approval_and_overlapping_requests_are_blocked(): void
    {
        $id = $this->authenticated()->postJson('/api/data.php', ['action' => 'deleteHistory', 'id' => $this->historyId, 'reason' => 'Incorrect log'])->assertStatus(202)->json('requestId');
        $this->api(['action' => 'create', 'targetType' => 'project', 'id' => $this->projectId, 'reason' => 'Overlapping project'])->assertConflict();
        $this->assertDatabaseCount('history', 1);
        $this->asUser(2); $token = $this->approve($id); $this->confirm($id, $token)->assertOk();
        $this->assertDatabaseCount('projects', 1); $this->assertDatabaseCount('history', 0);
        $this->asUser(1); $this->requestDeletion();
        $this->api(['action' => 'create', 'targetType' => 'project', 'id' => $this->projectId, 'reason' => 'Duplicate request'])->assertConflict();
    }

    public function test_admin_also_uses_two_steps_and_audit_identity_survives_renaming(): void
    {
        $this->asUser(2); $id = $this->requestDeletion();
        $this->assertDatabaseCount('projects', 1);
        $token = $this->approve($id); $this->asUser(5); $this->confirm($id, $token)->assertOk();
        DB::table('users')->where('id', 2)->update(['username' => 'renamed-admin', 'full_name' => 'Renamed']);
        $this->authenticated()->getJson($this->detailUrl($id))->assertJsonPath('request.reviewer.username', 'deletion2')->assertJsonPath('request.deletedBy.username', 'deletion5');
    }

    public function test_created_project_import_rollback_cannot_bypass_approval(): void
    {
        DB::table('import_jobs')->insert(['batch_id' => 'deletion_batch_123', 'actor_id' => 1, 'status' => 'completed']);
        DB::table('import_job_changes')->insert(['batch_id' => 'deletion_batch_123', 'project_id' => $this->projectId, 'change_type' => 'created']);
        $this->authenticated()->postJson('/api/data.php', ['action' => 'rollbackImport', 'batchId' => 'deletion_batch_123'])->assertUnprocessable();
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_production_csrf_exception_and_request_listing_filters(): void
    {
        $this->app['env'] = 'local';
        $id = $this->requestDeletion();
        $this->authenticated()->getJson('/api/deletion-requests.php?status=pending')->assertOk()->assertJsonPath('pendingCount', 1)->assertJsonCount(1, 'requests');
        $this->api(['action' => 'create', 'targetType' => 'project', 'id' => 999999, 'reason' => 'Missing'])->assertNotFound();
        $this->asUser(2); $token = $this->approve($id); $this->confirm($id, $token)->assertOk();
        $this->authenticated()->getJson('/api/deletion-requests.php?status=pending')->assertJsonPath('pendingCount', 0)->assertJsonCount(0, 'requests');
    }
}
