<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportRegressionTest extends TestCase
{
    public function test_session_refreshes_permissions_and_rejects_deleted_accounts(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('full_name');
            $table->string('role');
            $table->boolean('active');
            foreach (['can_create', 'can_edit', 'can_import', 'can_export', 'can_delete', 'can_delete_all', 'can_resolve_alerts', 'can_archive'] as $permission) $table->boolean($permission)->default(false);
        });
        DB::table('users')->insert(['id' => 8, 'username' => 'staff', 'full_name' => 'Staff', 'role' => 'staff', 'active' => true, 'can_edit' => false]);
        $session = ['tracker_user' => ['id' => 8, 'username' => 'staff', 'role' => 'staff', 'canEdit' => true], 'tracker_csrf' => 'test-token', 'tracker_created_at' => time(), 'tracker_last_activity' => time()];
        $this->withTrackerSession($session)->postJson('/api/auth.php', ['action' => 'session'])
            ->assertOk()->assertJsonPath('user.canEdit', false);
        DB::table('users')->where('id', 8)->delete();
        $this->withTrackerSession($session)->postJson('/api/auth.php', ['action' => 'session'])
            ->assertOk()->assertJsonPath('authenticated', false);
        $this->withTrackerSession($session)->getJson('/api/data.php')->assertUnauthorized();
    }

    public function test_completed_historical_import_can_omit_completion_date(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('full_name');
            $table->string('role');
            $table->boolean('active');
            foreach (['can_create', 'can_edit', 'can_import', 'can_export', 'can_delete', 'can_delete_all', 'can_resolve_alerts', 'can_archive'] as $permission) $table->boolean($permission)->default(false);
        });
        DB::table('users')->insert(['id' => 5, 'username' => 'admin', 'full_name' => 'Administrator', 'role' => 'admin', 'active' => true]);
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->text('data');
            foreach (['project_no', 'title', 'project_status', 'winning_bidder', 'source_of_fund', 'pr_number'] as $name) $table->string($name)->nullable();
            foreach (['bidding_date', 'completion_date', 'noa_date', 'ntp_date'] as $name) $table->date($name)->nullable();
            foreach (['approved_budget', 'bid_amount'] as $name) $table->decimal($name, 14, 2)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->string('batch_id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->string('status');
            $table->unsignedInteger('processed_count')->default(0);
        });
        Schema::create('import_job_changes', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id');
            $table->unsignedBigInteger('project_id');
            $table->string('change_type');
            $table->text('previous_data')->nullable();
            $table->text('applied_data')->nullable();
        });
        Schema::create('project_number_sequences', function (Blueprint $table) {
            $table->string('sequence_name')->primary();
            $table->unsignedInteger('next_number');
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
        DB::table('project_number_sequences')->insert(['sequence_name' => 'projects', 'next_number' => 1]);
        DB::connection()->getPdo()->sqliteCreateFunction('REGEXP', fn ($pattern, $value) => preg_match('/'.$pattern.'/', (string) $value), 2);

        $session = ['tracker_user' => ['id' => 5, 'username' => 'admin', 'role' => 'admin'], 'tracker_csrf' => 'test-token', 'tracker_created_at' => time(), 'tracker_last_activity' => time()];
        $response = $this->withTrackerSession($session)->withHeader('X-CSRF-Token', 'test-token')->postJson('/api/data.php', [
            'action' => 'bulkSaveProjects', 'batchId' => 'import_regression_1', 'archive' => true,
            'records' => [['title' => 'Historic Completed Project', 'projectNo' => '90001', 'status' => 'Completed', 'completion' => '']],
        ]);
        $response->assertOk()->assertJsonPath('count', 1);

        $row = DB::table('projects')->first();
        $this->assertSame('Completed', $row->project_status);
        $this->assertNull($row->completion_date);
        $this->assertNotNull($row->archived_at);
        $this->assertNotEmpty(json_decode($row->data, true)['archivedAt']);

        $this->withTrackerSession($session)->withHeader('X-CSRF-Token', 'test-token')->postJson('/api/data.php', [
            'action' => 'bulkSaveProjects', 'batchId' => 'import_regression_2',
            'records' => [['title' => 'Active Completed Project', 'projectNo' => '90002', 'status' => 'Completed', 'completion' => '']],
        ])->assertOk()->assertJsonPath('count', 1);
        $activeRow = DB::table('projects')->where('project_no', '90002')->first();
        $this->assertNull($activeRow->completion_date);
        $this->assertNull($activeRow->archived_at);
        $saved = json_decode($activeRow->data, true);
        $saved['attachments'] = [['name' => 'inspection.pdf', 'path' => 'stored/inspection.pdf']];
        DB::table('projects')->where('id', $activeRow->id)->update(['data' => json_encode($saved)]);

        $this->withTrackerSession($session)->withHeader('X-CSRF-Token', 'test-token')->postJson('/api/data.php', [
            'action' => 'bulkSaveProjects', 'batchId' => 'import_regression_4', 'archive' => true,
            'records' => [['id' => $activeRow->id, 'updatedAt' => $saved['updatedAt'], 'title' => 'Updated active project', 'projectNo' => '90002', 'status' => 'Completed', 'completion' => '']],
        ])->assertOk();
        $activeRow = DB::table('projects')->where('id', $activeRow->id)->first();
        $this->assertNull($activeRow->archived_at);
        $updated = json_decode($activeRow->data, true);
        $this->assertArrayNotHasKey('archivedAt', $updated);
        $this->assertSame($saved['attachments'], $updated['attachments']);

        $this->withTrackerSession($session)->withHeader('X-CSRF-Token', 'test-token')->postJson('/api/data.php', [
            'action' => 'bulkSaveProjects', 'batchId' => 'import_regression_3', 'archive' => true,
            'records' => [['title' => 'Duplicate Number', 'projectNo' => '90002', 'status' => 'Completed', 'completion' => '']],
        ])->assertStatus(422)->assertJsonPath('message', 'Project number 90002 already exists. Choose Add as new with a new number or overwrite the matching project.');
        $this->assertSame(2, DB::table('projects')->count());
    }
}
