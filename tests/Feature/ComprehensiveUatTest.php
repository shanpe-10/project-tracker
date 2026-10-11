<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ComprehensiveUatTest extends TestCase
{
    private array $session;
    private ?string $pgSchema = null;
    protected function tearDown(): void {
        if ($this->pgSchema) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA "'.$this->pgSchema.'" CASCADE');
        }
        parent::tearDown();
    }


    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (!str_ends_with((string)config('database.connections.pgsql.database'), '_test')) throw new \RuntimeException('Use a dedicated database ending in _test.');
            $this->pgSchema = 'test_'.bin2hex(random_bytes(6));
            DB::statement('CREATE SCHEMA "'.$this->pgSchema.'"');
            DB::statement('SET search_path TO "'.$this->pgSchema.'"');
            DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
            DB::table('users')->insert(['id'=>1,'username'=>'admin','full_name'=>'Administrator','password_hash'=>password_hash('test-password', PASSWORD_BCRYPT),'role'=>'staff','active'=>true,'can_create'=>true,'can_edit'=>true,'can_import'=>true,'can_export'=>true,'can_delete'=>true,'can_delete_all'=>true,'can_resolve_alerts'=>true,'can_archive'=>true]);
        } else {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('full_name');
            $table->string('password_hash')->nullable();
            $table->string('role');
            $table->boolean('active');
            foreach (['can_create', 'can_edit', 'can_import', 'can_export', 'can_delete', 'can_delete_all', 'can_resolve_alerts', 'can_archive'] as $permission) {
                $table->boolean($permission)->default(false);
            }
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
            $table->unsignedBigInteger('project_id')->nullable();
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
        Schema::create('application_settings', function (Blueprint $table) {
            $table->string('setting_key')->primary();
            $table->text('setting_value');
            $table->unsignedBigInteger('updated_by');
            $table->timestamp('updated_at');
        });
        Schema::create('project_number_sequences', function (Blueprint $table) {
            $table->string('sequence_name')->primary();
            $table->unsignedInteger('next_number');
        });
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->string('batch_id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->string('status');
            $table->unsignedInteger('processed_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
        });
        Schema::create('import_job_changes', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id');
            $table->unsignedBigInteger('project_id');
            $table->string('change_type');
            $table->text('previous_data')->nullable();
            $table->text('applied_data')->nullable();
        });
        DB::table('users')->insert(['id' => 1, 'username' => 'admin', 'full_name' => 'Administrator', 'role' => 'staff', 'active' => true,'can_create'=>true,'can_edit'=>true,'can_import'=>true,'can_export'=>true,'can_delete'=>true,'can_delete_all'=>true,'can_resolve_alerts'=>true,'can_archive'=>true]);
        DB::table('project_number_sequences')->insert(['sequence_name' => 'projects', 'next_number' => 1]);
        DB::connection()->getPdo()->sqliteCreateFunction('REGEXP', fn ($pattern, $value) => preg_match('/'.$pattern.'/', (string) $value), 2);
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT_WS', fn ($separator, ...$parts) => implode($separator, array_filter($parts, fn ($part) => $part !== null)), -1);
        DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value === null ? null : (json_decode($value, true) ?? $value), 1);
        DB::connection()->getPdo()->sqliteCreateFunction('STR_TO_DATE', fn ($value) => $value, 2);
        DB::connection()->getPdo()->sqliteCreateFunction('MONTH', fn ($value) => $value ? (int) substr($value, 5, 2) : null, 1);
        DB::connection()->getPdo()->sqliteCreateFunction('DAYOFMONTH', fn ($value) => $value ? (int) substr($value, 8, 2) : null, 1);
        DB::connection()->getPdo()->sqliteCreateFunction('YEAR', fn ($value) => $value ? (int) substr($value, 0, 4) : null, 1);
        }
        $this->session = ['tracker_user' => ['id' => 1, 'username' => 'admin', 'role' => 'admin'], 'tracker_csrf' => 'uat-token', 'tracker_created_at' => time(), 'tracker_last_activity' => time()];
    }

    private function postData(array $body)
    {
        return $this->withTrackerSession($this->session)->withHeader('X-CSRF-Token', 'uat-token')->postJson('/api/data.php', $body);
    }

    private function alerts(array $project): array
    {
        $method = new \ReflectionMethod(TrackerDataController::class, 'projectAlerts');
        return $method->invoke(new TrackerDataController, $project, ['noa' => 10, 'ntp' => 30, 'material' => 15, 'completion' => 15], new \DateTimeImmutable('2026-09-29'));
    }

    public function test_every_alert_phase_and_resolution(): void
    {
        $cases = [
            [['status' => 'Pending', 'noa' => '2026-10-02'], 'noa-warning'],
            [['status' => 'Pending', 'noa' => '2026-09-25'], 'noa-active'],
            [['status' => 'Pending', 'noa' => '2026-09-01'], 'noa-overdue'],
            [['status' => 'Pending', 'ntpDate' => '2026-09-25'], 'material-test'],
            [['status' => 'Pending', 'ntpDate' => '2026-09-25'], 'ntp-award'],
            [['status' => 'Pending', 'ntpDate' => '2026-09-01'], 'material-overdue'],
            [['status' => 'Pending', 'ntpDate' => '2026-08-01'], 'ntp-overdue'],
            [['status' => 'Completed', 'completion' => '2025-10-02'], 'award-close'],
            [['status' => 'Released', 'completion' => '2025-09-01'], 'award-overdue'],
        ];
        foreach ($cases as [$record, $expected]) {
            if (in_array($expected,['noa-active','noa-overdue','ntp-award','ntp-overdue'],true)) {
                $this->assertNotContains($expected,array_column($this->alerts($record),'type'));
            } else {
                $this->assertContains($expected, array_column($this->alerts($record), 'type'), $expected);
            }
        }
        $this->assertSame([], $this->alerts(['status' => 'Completed', 'noa' => '2026-09-01', 'ntpDate' => '2026-08-01']));
        $this->assertSame([], $this->alerts(['status' => 'Released', 'completion' => '2025-09-01', 'swa' => '2026-09-01']));
        $this->assertNotContains('noa-overdue', array_column($this->alerts(['status' => 'Pending', 'noa' => '2026-09-01', 'timelineAlertsCompleted' => ['noa-overdue']]), 'type'));
    }

    public function test_project_input_validation_and_dashboard_status_output(): void
    {
        $this->postData(['action' => 'saveProject', 'record' => ['title' => '']])->assertStatus(422);
        $this->postData(['action' => 'saveProject', 'record' => ['title' => 'UAT', 'status' => 'Completed']])->assertStatus(422);
        $this->postData(['action' => 'saveProject', 'record' => ['title' => 'UAT']])->assertStatus(422)->assertJsonPath('message', 'Date of Bidding is required and must be valid.');
        $this->postData(['action' => 'saveProject', 'record' => ['title' => 'UAT', 'biddingDate' => '2026-02-30']])->assertStatus(422);
        $this->postData(['action' => 'saveProject', 'record' => ['title' => 'UAT', 'biddingDate' => '2026-09-29']])->assertStatus(422)->assertJsonPath('message', 'Approved Budget is required and must be a non-negative amount with at most two decimal places.');
        $this->postData(['action' => 'saveProject', 'record' => ['title' => 'UAT', 'biddingDate' => '2026-09-29', 'approvedBudget' => '-1']])->assertStatus(422);
        $this->postData(['action' => 'saveProject', 'record' => ['title' => 'UAT', 'biddingDate' => '2026-09-29', 'approvedBudget' => '100']])->assertStatus(422)->assertJsonPath('message', 'Winning Bidder is required.');
        $saved = $this->postData(['action' => 'saveProject', 'record' => ['title' => 'Inspection UAT', 'status' => 'Final-inspection', 'finalInspection' => '2026-09-29', 'biddingDate' => '2026-09-01', 'approvedBudget' => '1,250.50', 'winningBidder' => 'Builder']])->assertOk()->json('record');
        $this->assertEqualsWithDelta(1250.50, (float) DB::table('projects')->where('id', $saved['id'])->value('approved_budget'), 0.001);
        $this->withTrackerSession($this->session)->getJson('/api/data.php?action=dashboard&year=2026&month=9')->assertOk()->assertJsonPath('statusCounts.Final-inspection', 1);
        DB::table('users')->where('id',1)->update(['role'=>'admin']);
        $this->postData(['action' => 'saveTimelineSettings', 'settings' => ['noa' => 0, 'ntp' => 30, 'material' => 15, 'completion' => 15]])->assertStatus(422);
        $this->postData(['action' => 'saveTimelineSettings', 'settings' => ['noa' => 10, 'ntp' => 30, 'material' => 15, 'completion' => 15]])->assertOk();
    }

    public function test_import_export_and_rollback_are_consistent(): void
    {
        $batch = 'uat_import_batch_01';
        $this->postData(['action' => 'bulkSaveProjects', 'batchId' => $batch, 'archive' => true, 'records' => [
            ['projectNo' => '9901', 'title' => 'Historic bridge', 'status' => 'Completed', 'completion' => '', 'remarks' => 'Brgy. 2'],
            ['projectNo' => '9902', 'title' => 'Historic road', 'status' => 'Released', 'completion' => '2026-02-01'],
        ]])->assertOk()->assertJsonPath('count', 2);
        $this->withTrackerSession($this->session)->getJson('/api/data.php?action=listProjects&lifecycle=archived&status=Completed')->assertOk()->assertJsonPath('totalRecords', 1);
        $response = $this->withTrackerSession($this->session)->get('/api/data.php?action=listProjects&lifecycle=archived&format=csv');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
        $csv = $response->streamedContent();
        $header = str_getcsv(ltrim(strtok($csv, "\n"), "\xEF\xBB\xBF"));
        $this->assertSame(['Project No.', 'Date of Bidding', 'Project Title'], array_slice($header, 0, 3));
        $this->assertStringContainsString('Historic bridge', $csv);
        $this->assertStringContainsString('Historic road', $csv);
        $this->postData(['action' => 'rollbackImport', 'batchId' => $batch])->assertOk()->assertJsonPath('count', 2);
        $this->assertSame(0, DB::table('projects')->count());
    }

    public function test_import_failure_rolls_back_every_row(): void
    {
        $this->postData(['action' => 'bulkSaveProjects', 'batchId' => 'bad', 'records' => [['title' => 'Valid']]])->assertStatus(422);
        $this->postData(['action' => 'bulkSaveProjects', 'batchId' => 'uat_import_empty', 'records' => []])->assertStatus(422);
        $this->postData(['action' => 'bulkSaveProjects', 'batchId' => 'uat_import_fail_01', 'records' => [
            ['projectNo' => '1', 'title' => 'Valid'],
            ['projectNo' => '2', 'title' => ''],
        ]])->assertStatus(422);
        $this->assertSame(0, DB::table('projects')->count());
        $this->assertSame(0, DB::table('import_job_changes')->count());
    }

    public function test_import_batch_cannot_be_reused_after_completion_or_rollback(): void
    {
        $batch = 'uat_reused_batch_01';
        $payload = ['action' => 'bulkSaveProjects', 'batchId' => $batch, 'records' => [['projectNo' => '9101', 'title' => 'First']]];
        $this->postData($payload)->assertOk();
        $this->postData(['action' => 'completeImport', 'batchId' => $batch])->assertOk();
        $this->postData($payload)->assertStatus(422);
        $this->postData(['action' => 'completeImport', 'batchId' => $batch])->assertStatus(409);
        $this->postData(['action' => 'rollbackImport', 'batchId' => $batch])->assertOk();
        $this->postData($payload)->assertStatus(422);
        $this->assertSame(0, DB::table('projects')->count());
    }

    public function test_invalid_calendar_dates_are_rejected_or_ignored(): void
    {
        $this->assertFalse(TrackerDataController::validProjectDate('2026-02-30'));
        $this->assertNull(TrackerDataController::projectColumns(['completion' => '2026-02-30'])['completion_date']);
        $this->postData(['action' => 'bulkSaveProjects', 'batchId' => 'uat_invalid_date_01', 'records' => [
            ['projectNo' => '9201', 'title' => 'Invalid date', 'completion' => '2026-02-30'],
        ]])->assertStatus(422);
        $this->assertSame(0, DB::table('projects')->count());
        $this->postData(['action' => 'saveProject', 'record' => [
            'title' => 'Invalid completion', 'biddingDate' => '2026-09-29', 'approvedBudget' => '100',
            'winningBidder' => 'Builder', 'status' => 'Completed', 'completion' => '2026-02-30',
        ]])->assertStatus(422);
    }

    public function test_staff_permissions_block_protected_inputs_and_outputs(): void
    {
        DB::table('users')->insert(['id' => 2, 'username' => 'viewer', 'full_name' => 'Viewer', 'password_hash' => password_hash('test-password', PASSWORD_BCRYPT), 'role' => 'staff', 'active' => true]);
        $viewer = ['tracker_user' => ['id' => 2, 'username' => 'viewer', 'role' => 'staff', 'canCreate' => false, 'canEdit' => false, 'canImport' => false, 'canExport' => false, 'canArchive' => false], 'tracker_csrf' => 'view-token', 'tracker_created_at' => time(), 'tracker_last_activity' => time()];
        $this->withTrackerSession($viewer)->withHeader('X-CSRF-Token', 'view-token')->postJson('/api/data.php', ['action' => 'saveProject', 'record' => ['title' => 'Not allowed']])->assertForbidden();
        $this->withTrackerSession($viewer)->withHeader('X-CSRF-Token', 'view-token')->postJson('/api/data.php', ['action' => 'bulkSaveProjects', 'batchId' => 'uat_viewer_01', 'records' => [['title' => 'Not allowed']]])->assertForbidden();
        $this->withTrackerSession($viewer)->withHeader('X-CSRF-Token', 'view-token')->postJson('/api/data.php', ['action' => 'saveTimelineSettings', 'settings' => ['noa' => 10, 'ntp' => 30, 'material' => 15, 'completion' => 15]])->assertForbidden();
        $this->withTrackerSession($viewer)->get('/api/data.php?action=listProjects&format=csv')->assertForbidden();
    }

    public function test_barangay_status_and_milestone_date_filters(): void
    {
        $rows = [
            ['projectNo' => '11', 'title' => 'Hall at Brgy. 2 Laoag', 'status' => 'Final-inspection', 'finalInspection' => '2026-08-15'],
            ['projectNo' => '12', 'title' => 'Road at Sitio 2, Brgy. 3 Laoag', 'status' => 'Final-inspection', 'finalInspection' => '2026-09-15'],
            ['projectNo' => '13', 'title' => 'Unit 2 at Brgy. 20 Laoag', 'status' => 'Pending', 'finalInspection' => '2026-08-15'],
        ];
        foreach ($rows as $row) {
            DB::table('projects')->insert(['data' => json_encode($row)] + TrackerDataController::projectColumns($row) + ['updated_at' => now()]);
        }
        $url = '/api/data.php?action=listProjects&lifecycle=all&pageSize=10';
        $this->withTrackerSession($this->session)->getJson($url.'&brgy=2')->assertOk()->assertJsonPath('totalRecords', 1)->assertJsonPath('projects.0.projectNo', '11');
        $this->withTrackerSession($this->session)->getJson($url.'&status=Final-inspection&dateField=finalInspection&month=8&year=2026')->assertOk()->assertJsonPath('totalRecords', 1)->assertJsonPath('projects.0.projectNo', '11');
        $this->withTrackerSession($this->session)->getJson($url.'&projectNo=12')->assertOk()->assertJsonPath('totalRecords', 1)->assertJsonPath('projects.0.projectNo', '12');
    }

    public function test_attachment_input_and_output(): void
    {
        $record = ['projectNo' => '9903', 'title' => 'File UAT', 'status' => 'Pending', 'updatedAt' => '2026-09-29T00:00:00Z'];
        $id = DB::table('projects')->insertGetId(['data' => json_encode($record)] + TrackerDataController::projectColumns($record) + ['updated_at' => now()]);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tracker-uat-'.bin2hex(random_bytes(4));
        config(['tracker.attachments' => $directory]);
        try {
            $this->withTrackerSession($this->session)->withHeader('X-CSRF-Token', 'uat-token')->post('/api/project-edit.php', [
                'projectId' => $id, 'record' => json_encode($record), 'deleteIds' => '[]',
                'files' => [UploadedFile::fake()->createWithContent('malware.php', '<?php echo "bad";')],
            ])->assertStatus(422);
            $this->withTrackerSession($this->session)->withHeader('X-CSRF-Token', 'uat-token')->post('/api/project-edit.php', [
                'projectId' => $id, 'record' => json_encode($record), 'deleteIds' => '[]',
                'files' => [UploadedFile::fake()->createWithContent('evidence.txt', 'Test evidence')],
            ])->assertOk()->assertJsonCount(1, 'record.attachments');
            $stored = json_decode(DB::table('projects')->where('id', $id)->value('data'), true);
            $attachmentId = $stored['attachments'][0]['id'];
            $this->withTrackerSession($this->session)->get('/api/attachments.php?projectId='.$id.'&attachmentId='.$attachmentId.'&download=1')->assertOk()->assertDownload('evidence.txt');
            $this->withTrackerSession($this->session)->getJson('/api/attachments.php?projectId='.$id.'&attachmentId=missing')->assertNotFound();
            $this->withTrackerSession($this->session)->withHeader('X-CSRF-Token', 'uat-token')->postJson('/api/attachments.php', ['projectId' => $id, 'action' => 'delete', 'attachmentId' => $attachmentId])->assertOk()->assertJsonCount(0, 'attachments');
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.$id.DIRECTORY_SEPARATOR.'*') ?: [] as $path) @unlink($path);
            @rmdir($directory.DIRECTORY_SEPARATOR.$id);
            @rmdir($directory);
        }
    }
}
