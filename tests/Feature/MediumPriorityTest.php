<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MediumPriorityTest extends TestCase
{
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('full_name');
            $table->string('role');
            $table->boolean('active');
            foreach (['can_create', 'can_edit', 'can_import', 'can_export', 'can_delete', 'can_delete_all', 'can_resolve_alerts', 'can_archive'] as $permission) $table->boolean($permission)->default(false);
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->text('data');
            foreach (['project_no', 'title', 'project_status', 'winning_bidder', 'source_of_fund', 'pr_number'] as $name) $table->string($name)->nullable();
            foreach (['bidding_date', 'completion_date', 'noa_date', 'ntp_date'] as $name) $table->date($name)->nullable();
            foreach (['approved_budget', 'bid_amount'] as $name) $table->decimal($name, 14, 2)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('history', function (Blueprint $table) {
            $table->id();
            $table->text('data');
            $table->unsignedBigInteger('project_id');
            $table->timestamp('event_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_username');
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('summary');
            $table->timestamp('created_at');
        });
        DB::table('users')->insert(['id' => 5, 'username' => 'admin', 'full_name' => 'Administrator', 'role' => 'admin', 'active' => true]);
        $this->session = ['tracker_user' => ['id' => 5, 'username' => 'admin', 'role' => 'admin'], 'tracker_csrf' => 'test-token', 'tracker_created_at' => time(), 'tracker_last_activity' => time()];
    }

    private function project(array $record): int
    {
        return DB::table('projects')->insertGetId(['data' => json_encode($record)] + TrackerDataController::projectColumns($record) + ['updated_at' => now()]);
    }

    public function test_recorded_noa_and_swa_dates_resolve_their_reminders(): void
    {
        $method = new \ReflectionMethod(TrackerDataController::class, 'projectAlerts');
        $controller = new TrackerDataController;
        $today = new \DateTimeImmutable('2026-09-28');
        $settings = ['noa' => 10, 'ntp' => 30, 'material' => 15, 'completion' => 15];
        $alerts = $method->invoke($controller, ['status' => 'Pending', 'noa' => '2025-01-01'], $settings, $today);
        // Recorded NOA dates resolve automatically under the current business rule.
        $this->assertNotContains('noa-overdue', array_column($alerts, 'type'));
        $alerts = $method->invoke($controller, ['status' => 'Completed', 'completion' => '2024-01-01', 'swa' => '2025-01-01'], $settings, $today);
        $this->assertNotContains('award-overdue', array_column($alerts, 'type'));
    }

    public function test_legacy_completed_project_can_be_edited_atomically(): void
    {
        $before = ['projectNo' => '71', 'title' => 'Original', 'status' => 'Completed', 'completion' => '', 'updatedAt' => '2026-09-27T00:00:00Z'];
        $id = $this->project($before);
        $updated = $before;
        $updated['title'] = 'Corrected title';
        $this->withTrackerSession($this->session)->withHeader('X-CSRF-Token', 'test-token')->post('/api/project-edit.php', [
            'projectId' => $id, 'record' => json_encode($updated), 'deleteIds' => '[]',
        ])->assertOk()->assertJsonPath('record.title', 'Corrected title');
        $this->assertSame('Corrected title', DB::table('projects')->where('id', $id)->value('title'));
        $this->assertNull(DB::table('projects')->where('id', $id)->value('completion_date'));
        $this->assertSame(1, DB::table('history')->where('project_id', $id)->count());
        $this->assertSame(1, DB::table('audit_log')->where('entity_id', $id)->count());
    }

    public function test_failed_attachment_change_rolls_back_project_and_history(): void
    {
        $before = ['projectNo' => '72', 'title' => 'Original', 'status' => 'Pending', 'updatedAt' => '2026-09-27T00:00:00Z'];
        $id = $this->project($before);
        $updated = $before;
        $updated['title'] = 'Should not save';
        $this->withTrackerSession($this->session)->withHeader('X-CSRF-Token', 'test-token')->post('/api/project-edit.php', [
            'projectId' => $id, 'record' => json_encode($updated), 'deleteIds' => '["missing-attachment"]',
        ])->assertStatus(422);
        $this->assertSame('Original', DB::table('projects')->where('id', $id)->value('title'));
        $this->assertSame(0, DB::table('history')->count());
    }

    public function test_archive_and_restore_create_history_and_audit_entries(): void
    {
        $id = $this->project(['projectNo' => '73', 'title' => 'Archive me', 'status' => 'Completed', 'completion' => '2025-01-01']);
        foreach ([true, false] as $archived) {
            $this->withTrackerSession($this->session)->withHeader('X-CSRF-Token', 'test-token')->postJson('/api/data.php', [
                'action' => 'setProjectArchived', 'id' => $id, 'archived' => $archived,
            ])->assertOk();
        }
        $this->assertNull(DB::table('projects')->where('id', $id)->value('archived_at'));
        $this->assertSame(2, DB::table('history')->where('project_id', $id)->count());
        $this->assertSame(['project.archived', 'project.restored'], DB::table('audit_log')->orderBy('id')->pluck('action')->all());
    }
}
