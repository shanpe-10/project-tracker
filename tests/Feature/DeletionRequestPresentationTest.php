<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeletionRequestPresentationTest extends TestCase
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

    public function test_approval_moves_a_personal_request_to_history_but_keeps_it_in_the_admin_queue(): void
    {
        $id=$this->requestDeletion();$this->asUser(2);$token=$this->approve($id);$this->asUser(1);
        $this->authenticated()->getJson('/api/deletion-requests.php')->assertJsonCount(0,'requests')->assertJsonPath('pendingCount',0);
        $this->authenticated()->getJson('/api/deletion-requests.php?view=history')->assertJsonCount(1,'requests')->assertJsonPath('requests.0.stage','approved')->assertJsonPath('requests.0.title','Approval project');
        $this->assertDatabaseCount('projects',1);
        $this->asUser(2);$this->authenticated()->getJson('/api/deletion-requests.php?scope=review')->assertJsonCount(1,'requests')->assertJsonPath('requests.0.stage','approved')->assertJsonPath('pendingCount',1);
        $this->confirm($id,$token)->assertOk();
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=review')->assertJsonCount(0,'requests');
        $this->asUser(1);$this->authenticated()->getJson('/api/deletion-requests.php?view=history')->assertJsonPath('requests.0.stage','deleted');
    }

    public function test_rejection_clears_active_views_and_preserves_the_project_and_request_history(): void
    {
        $id=$this->requestDeletion();$this->asUser(2);$this->api(['action'=>'review','id'=>$id,'decision'=>'reject'])->assertOk();
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=review&view=active')->assertJsonCount(0,'requests');
        $this->asUser(1);$this->authenticated()->getJson('/api/deletion-requests.php?view=active')->assertJsonCount(0,'requests');
        $this->authenticated()->getJson('/api/deletion-requests.php?view=history&status=rejected')->assertJsonCount(1,'requests')->assertJsonPath('requests.0.id',$id);
        $this->assertDatabaseCount('projects',1);$this->assertDatabaseCount('history',1);$this->assertDatabaseCount('deletion_requests',1);
    }

    public function test_cancelled_requests_stay_out_of_active_and_can_be_filtered_in_history(): void
    {
        $id=$this->requestDeletion();$this->api(['action'=>'cancel','id'=>$id])->assertOk();
        $this->authenticated()->getJson('/api/deletion-requests.php?view=active')->assertJsonCount(0,'requests');
        $this->authenticated()->getJson('/api/deletion-requests.php?view=history&status=cancelled')->assertJsonCount(1,'requests')->assertJsonPath('requests.0.id',$id);
        $fresh=$this->requestDeletion();$this->authenticated()->getJson('/api/deletion-requests.php?view=active')->assertJsonCount(1,'requests')->assertJsonPath('requests.0.id',$fresh);
    }

    public function test_explicit_personal_history_remains_private_and_admin_review_history_is_available(): void
    {
        $id=$this->requestDeletion();$this->asUser(2);$this->api(['action'=>'review','id'=>$id,'decision'=>'reject'])->assertOk();
        $this->authenticated()->getJson('/api/deletion-requests.php?view=history&scope=mine')->assertJsonCount(0,'requests');
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=review&view=history')->assertJsonCount(1,'requests');
        $this->asUser(3);$this->authenticated()->getJson('/api/deletion-requests.php?view=history&scope=mine')->assertJsonCount(0,'requests');
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=review&view=history')->assertForbidden();
    }

}
