<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use App\Support\TrackerProjectInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectSafeguardsTest extends TestCase
{
    private int $actor = 2;
    private const PASSWORD = 'Fixture-admin-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('Requires the isolated PostgreSQL test database.');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        $this->useWeekdayCalendarFixture();
        foreach ([1 => 'staff', 2 => 'admin', 3 => 'admin'] as $id => $role) {
            DB::table('users')->insert(['id' => $id, 'username' => 'safeguard'.$id, 'full_name' => 'Safeguard '.$id,
                'role' => $role, 'active' => true, 'can_delete' => true, 'can_delete_all' => true,
                'can_create' => true, 'can_edit' => true, 'can_import' => true,
                'password_hash' => Hash::make($id === 3 ? 'Other-admin-2026!' : self::PASSWORD)]);
            DB::table('tracker_account_sessions')->insert(['user_id' => $id, 'token_hash' => hash('sha256', 'safeguard-'.$id),
                'started_at' => time(), 'last_activity_at' => time()]);
        }
    }

    private function api(array $body, string $endpoint = 'deletion-requests.php')
    {
        $this->withSession(['tracker_user' => ['id' => $this->actor], 'tracker_csrf' => 'safeguard-csrf',
            'tracker_session_token' => 'safeguard-'.$this->actor]);
        return $this->withHeader('X-CSRF-Token', 'safeguard-csrf')->postJson('/api/'.$endpoint, $body);
    }

    private function project(array $extra = [], bool $archive = false): int
    {
        $data = $extra + ['title' => 'Safeguard project', 'approvedBudget' => '1000', 'updatedAt' => '2026-10-01T00:00:00Z'];
        return DB::table('projects')->insertGetId(['data' => json_encode($data), 'archived_at' => $archive ? now() : null]
            + TrackerDataController::projectColumns($data));
    }

    private function prepare(array $target): string
    {
        $preview = $this->api(['action' => 'previewDirect'] + $target)->assertOk()->json('selection');
        return $this->api(['action' => 'prepareDirect', 'expectedVersion' => $preview['version']] + $target)
            ->assertOk()->json('confirmationToken');
    }

    private function confirm(string $token, ?string $password = self::PASSWORD)
    {
        return $this->api(['action' => 'confirmDirect', 'confirmationToken' => $token, 'confirmed' => true]
            + ($password === null ? [] : ['current_password' => $password]));
    }

    public function test_project_save_rejects_new_final_inspection_and_completion_without_prerequisites(): void
    {
        foreach ([['finalInspection' => '2026-10-06'], ['completion' => '2026-10-07'],
            ['finalInspection' => '2026-10-06', 'completion' => '2026-10-07']] as $dates) {
            $this->api(['action' => 'saveProject', 'record' => $dates + ['title' => 'Invalid dates', 'approvedBudget' => '1000']], 'data.php')
                ->assertUnprocessable()->assertJsonPath('ok', false);
        }
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('history', 0);
    }

    public function test_valid_sequence_saves_and_contract_due_date_is_recomputed(): void
    {
        $this->api(['action' => 'saveProject', 'record' => ['title' => 'Valid dates', 'approvedBudget' => '1000',
            'preInspection' => '2026-10-05', 'finalInspection' => '2026-10-06', 'completion' => '2026-10-07',
            'contractRef' => '2026-10-01', 'contractDuration' => '5', 'contractEndDate' => '2026-10-30']], 'data.php')->assertOk();
        $saved = json_decode(DB::table('projects')->value('data'), true);
        $this->assertSame('2026-10-08', $saved['contractEndDate']);
        $this->assertSame('2026-10-07', $saved['completion']);
    }

    public function test_clearing_prerequisites_is_rejected_and_unchanged_legacy_dates_are_retained(): void
    {
        $valid = ['preInspection' => '2026-10-05', 'finalInspection' => '2026-10-06', 'completion' => '2026-10-07'];
        foreach (['preInspection', 'finalInspection'] as $required) {
            try {
                TrackerProjectInput::validateDates(array_replace($valid, [$required => '']), $valid);
                $this->fail('Clearing a prerequisite must be rejected.');
            } catch (ValidationException $error) {
                $this->assertStringContainsString('before entering', $error->getMessage());
            }
        }
        $legacy = ['finalInspection' => '2026-10-06', 'completion' => '2026-10-07'];
        TrackerProjectInput::validateDates($legacy + ['remarks' => 'Keep historical values'], $legacy);
        $this->addToAssertionCount(1);
    }

    public function test_import_validation_rejects_new_invalid_sequences_without_staging_or_saving(): void
    {
        $this->api(['action' => 'bulkSaveProjects', 'batchId' => 'safeguards-invalid-import',
            'records' => [['title' => 'Invalid import', 'completion' => '2026-10-07']]], 'data.php')->assertUnprocessable();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('import_jobs', 0);
    }

    public function test_attachment_edit_validates_the_merged_record_and_keeps_saved_prerequisites(): void
    {
        $id = $this->project(['preInspection' => '2026-10-05', 'finalInspection' => '2026-10-06', 'completion' => '2026-10-07']);
        $edit = ['title' => 'Safeguard project', 'updatedAt' => '2026-10-01T00:00:00Z'];
        $this->api(['projectId' => $id, 'record' => json_encode($edit + ['preInspection' => ''])], 'project-edit.php')
            ->assertUnprocessable()->assertJsonPath('message', 'Record Pre-Inspection before entering Final Inspection.');
        $this->assertSame('2026-10-05', json_decode(DB::table('projects')->where('id', $id)->value('data'), true)['preInspection']);
        $this->api(['projectId' => $id, 'record' => json_encode($edit + ['completion' => '2026-10-08'])], 'project-edit.php')->assertOk();
        $saved = json_decode(DB::table('projects')->where('id', $id)->value('data'), true);
        $this->assertSame('2026-10-06', $saved['finalInspection']);
        $this->assertSame('2026-10-08', $saved['completion']);
    }

    public function test_partial_import_can_use_previously_recorded_inspection_dates(): void
    {
        $id = $this->project(['preInspection' => '2026-10-05', 'finalInspection' => '2026-10-06']);
        $batch = 'safeguards-valid-import';
        $this->api(['action' => 'bulkSaveProjects', 'batchId' => $batch, 'records' => [['id' => $id,
            'title' => 'Safeguard project', 'updatedAt' => '2026-10-01T00:00:00Z', 'completion' => '2026-10-07']]], 'data.php')->assertOk();
        $this->api(['action' => 'completeImport', 'batchId' => $batch], 'data.php')->assertOk();
        $saved = json_decode(DB::table('projects')->where('id', $id)->value('data'), true);
        $this->assertSame('2026-10-05', $saved['preInspection']);
        $this->assertSame('2026-10-06', $saved['finalInspection']);
        $this->assertSame('2026-10-07', $saved['completion']);
    }

    public function test_archive_deletion_requires_the_current_admin_password_and_keeps_data_on_failure(): void
    {
        $id = $this->project([], true);
        DB::table('history')->insert(['project_id' => $id, 'data' => '{"action":"Archived"}', 'updated_at' => now()]);
        $token = $this->prepare(['targetType' => 'project', 'id' => $id]);
        $this->confirm($token, null)->assertUnprocessable();
        $this->confirm($token, 'wrong-password')->assertForbidden();
        $this->confirm($token, 'Other-admin-2026!')->assertForbidden();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('history', 1);
        $this->confirm($token)->assertOk();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('history', 0);
        $this->assertStringNotContainsString(self::PASSWORD, DB::table('audit_log')->pluck('metadata')->toJson());
        $this->confirm($token)->assertConflict();
    }

    public function test_delete_all_requires_password_and_only_deletes_the_reviewed_active_and_archive_selection(): void
    {
        $this->project(); $this->project([], true);
        $target = ['targetType' => 'project', 'all' => true];
        $this->api(['action' => 'previewDirect'] + $target)->assertOk()->assertJsonPath('selection.targetCount', 2);
        $token = $this->prepare($target);
        $later = $this->project(['title' => 'Added later']);
        $this->confirm($token, 'wrong-password')->assertForbidden();
        $this->assertDatabaseCount('projects', 3);
        $this->confirm($token)->assertOk();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('projects', ['id' => $later]);
    }

    public function test_approved_staff_deletion_requests_require_password_at_final_confirmation(): void
    {
        $id = $this->project(); $this->actor = 1;
        $requestId = $this->api(['action' => 'create', 'targetType' => 'project', 'id' => $id, 'reason' => 'Duplicate'])
            ->assertStatus(202)->json('requestId');
        $this->actor = 2;
        $this->api(['action' => 'session'], 'auth.php')->assertOk();
        $detail = $this->getJson('/api/deletion-requests.php?id='.$requestId)->assertOk()->json('request');
        $token = $this->api(['action' => 'review', 'id' => $requestId, 'decision' => 'approve', 'expectedVersion' => $detail['version']])
            ->assertOk()->json('confirmationToken');
        $body = ['action' => 'confirm', 'id' => $requestId, 'confirmationToken' => $token, 'confirmed' => true];
        $this->api($body)->assertUnprocessable();
        $this->api($body + ['current_password' => 'wrong-password'])->assertForbidden();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('deletion_requests', ['id' => $requestId, 'status' => 'approved']);
        $this->api($body + ['current_password' => self::PASSWORD])->assertOk();
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_staff_cannot_use_delete_all_even_when_the_legacy_permission_is_enabled(): void
    {
        $this->project(); $this->actor = 1;
        $this->api(['action' => 'session'], 'auth.php')->assertOk()->assertJsonPath('user.canDeleteAll', false);
        $this->api(['action' => 'create', 'targetType' => 'project', 'all' => true, 'reason' => 'Delete all'])->assertForbidden();
        foreach (['projects', 'history'] as $store) {
            $this->api(['action' => 'clearStore', 'store' => $store, 'reason' => 'Delete all'], 'data.php')->assertForbidden();
        }
        $this->api(['action' => 'previewDirect', 'targetType' => 'project', 'all' => true])->assertForbidden();
        $this->actor = 2;
        $this->api(['action' => 'setUserPermission', 'id' => 1, 'permission' => 'canDeleteAll', 'allowed' => true], 'auth.php')->assertUnprocessable();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('deletion_requests', 0);
    }

    public function test_repeated_wrong_passwords_are_rate_limited_without_deletion(): void
    {
        $id = $this->project(); $token = $this->prepare(['targetType' => 'project', 'id' => $id]);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->confirm($token, 'wrong-password')->assertStatus($attempt < 5 ? 403 : 429);
        }
        $this->confirm($token)->assertStatus(429);
        $this->assertDatabaseCount('projects', 1);
        DB::table('login_attempts')->update(['locked_until' => now()->subMinute(), 'first_failed_at' => now()->subMinutes(16)]);
        $this->confirm($token)->assertOk();
        $this->assertDatabaseCount('projects', 0);
    }
}
