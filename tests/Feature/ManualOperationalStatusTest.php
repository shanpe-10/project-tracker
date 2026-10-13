<?php

namespace Tests\Feature;

use App\Support\TrackerProjectInput;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualOperationalStatusTest extends TestCase
{
    private ?string $schema = null;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL required.');
        if (!str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'manual_status_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        DB::table('users')->insert(['id' => 1, 'username' => 'status-admin', 'full_name' => 'Status Admin',
            'role' => 'admin', 'active' => true, 'password_hash' => 'fixture']);
        DB::table('users')->insert(['id' => 2, 'username' => 'status-viewer', 'full_name' => 'Status Viewer',
            'role' => 'staff', 'active' => true, 'password_hash' => 'fixture']);
        $this->session = ['tracker_user' => ['id' => 1], 'tracker_csrf' => 'status-token',
            'tracker_created_at' => time(), 'tracker_last_activity' => time()];
    }

    protected function tearDown(): void
    {
        if ($this->schema) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    private function api(array $body, string $endpoint = 'data.php')
    {
        return $this->withTrackerSession($this->session)->withHeader('X-CSRF-Token', 'status-token')->postJson('/api/'.$endpoint, $body);
    }

    private function create(array $fields = []): array
    {
        return $this->api(['action' => 'saveProject', 'record' => array_replace([
            'title' => 'Manual status project', 'approvedBudget' => '1000', 'manualStatus' => 'Pending',
        ], $fields)])->assertOk()->json('record');
    }

    public function test_every_manual_status_has_priority_over_all_date_rules(): void
    {
        foreach (TrackerProjectInput::STATUSES as $manual) {
            $record = ['manualStatus' => $manual, 'status' => 'Released', 'swa' => '2026-10-09',
                'completion' => '2026-10-08', 'finalInspection' => '2026-10-07', 'contractEndDate' => '2026-10-06'];
            $this->assertSame($manual, TrackerProjectInput::normalize($record)['status']);
            $this->assertSame($manual, TrackerProjectInput::effectiveStatus($record, new \DateTimeImmutable('2027-01-01')));
        }
        $this->assertSame('Released', TrackerProjectInput::normalize(['status' => 'Released'])['status']);
        $this->assertSame('On-going Bidding', TrackerProjectInput::normalize(['status' => 'Released', 'manualStatus' => null])['status']);
        $this->assertSame('Pre-inspection', TrackerProjectInput::normalize(['status' => 'Completed', 'manualStatus' => '', 'biddingDate' => '2026-10-01'])['status']);
    }

    public function test_create_filters_dashboard_and_scheduler_use_manual_status(): void
    {
        $record = $this->create(['finalInspection' => '2026-10-09', 'manualStatus' => 'Pre-inspection']);
        $this->assertSame('Pre-inspection', $record['status']);
        $this->assertSame('Pre-inspection', DB::table('projects')->where('id', $record['id'])->value('project_status'));
        $this->withTrackerSession($this->session)->getJson('/api/data.php?action=listProjects&status=Pre-inspection')
            ->assertOk()->assertJsonPath('totalRecords', 1);
        $this->withTrackerSession($this->session)->getJson('/api/data.php?action=dashboard')
            ->assertOk()->assertJsonPath('metrics.pending', 0);
        $this->artisan('tracker:project-status-sync')->assertSuccessful();
        $stored = json_decode(DB::table('projects')->where('id', $record['id'])->value('data'), true);
        $this->assertSame($record['updatedAt'], $stored['updatedAt']);
        $this->assertSame('Pre-inspection', $stored['status']);
    }

    public function test_editor_dates_cannot_replace_override_and_automatic_can_be_restored(): void
    {
        $record = $this->create();
        $record['swa'] = '2026-10-09';
        $record['status'] = 'Released'; // The backend uses the override even if a client disagrees.
        $record = $this->api(['projectId' => $record['id'], 'record' => json_encode($record), 'deleteIds' => '[]'], 'project-edit.php')
            ->assertOk()->assertJsonPath('record.status', 'Pending')->assertJsonPath('record.manualStatus', 'Pending')->json('record');
        $record['manualStatus'] = null;
        $record = $this->api(['projectId' => $record['id'], 'record' => json_encode($record), 'deleteIds' => '[]'], 'project-edit.php')
            ->assertOk()->assertJsonPath('record.status', 'Released')->assertJsonPath('record.manualStatus', null)->json('record');
        $this->assertSame('Released', DB::table('projects')->where('id', $record['id'])->value('project_status'));
        $snapshot = json_decode(json_decode(DB::table('history')->orderByDesc('id')->value('data'), true)['changes'], true);
        $this->assertSame('Pending', $snapshot['manualStatus']);
    }

    public function test_clearing_manual_terminal_status_recalculates_without_latching_old_status(): void
    {
        $record = $this->create(['manualStatus' => 'Released']);
        $record['manualStatus'] = null;
        $this->api(['action' => 'saveProject', 'record' => $record])->assertOk()
            ->assertJsonPath('record.status', 'On-going Bidding')->assertJsonPath('record.manualStatus', null);
    }

    public function test_missing_override_field_in_older_clients_and_imports_preserves_it(): void
    {
        $record = $this->create(['manualStatus' => 'Final-inspection']);
        unset($record['manualStatus']);
        $record = $this->api(['action' => 'saveProject', 'record' => $record])->assertOk()
            ->assertJsonPath('record.status', 'Final-inspection')->assertJsonPath('record.manualStatus', 'Final-inspection')->json('record');
        unset($record['manualStatus']);
        $this->api(['action' => 'bulkSaveProjects', 'batchId' => 'manual-status-import', 'records' => [$record]])->assertOk();
        $stored = json_decode(DB::table('projects')->where('id', $record['id'])->value('data'), true);
        $this->assertSame('Final-inspection', $stored['manualStatus']);
        $this->assertSame('Final-inspection', $stored['status']);
        $csv = $this->withTrackerSession($this->session)->get('/api/data.php?action=listProjects&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Manual Operational Status', $csv);
        $this->assertStringContainsString('Final-inspection', $csv);
    }

    public function test_import_override_is_applied_and_blank_export_field_returns_to_automatic(): void
    {
        $record = $this->create(['manualStatus' => 'Pending']);
        $record['manualStatus'] = 'On-going Bidding';
        $record['finalInspection'] = '2026-10-09';
        $this->api(['action' => 'bulkSaveProjects', 'batchId' => 'manual-status-explicit', 'records' => [$record]])->assertOk();
        $stored = json_decode(DB::table('projects')->where('id', $record['id'])->value('data'), true);
        $this->assertSame('On-going Bidding', $stored['status']);
        $stored['manualStatus'] = null;
        $stored['id'] = $record['id'];
        $this->api(['action' => 'bulkSaveProjects', 'batchId' => 'manual-status-auto', 'records' => [$stored]])->assertOk();
        $this->assertSame('Pending', DB::table('projects')->where('id', $record['id'])->value('project_status'));
    }

    public function test_formatted_export_preserves_override_as_a_separate_text_column(): void
    {
        $path = \App\Support\FormattedProjectExport::create([
            ['title' => 'Override export', 'status' => 'Pending', 'manualStatus' => 'Pending'],
            ['title' => 'Automatic export', 'status' => 'On-going Bidding', 'manualStatus' => null],
        ], public_path('templates/project-export-template.xlsx'));
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            $document = new \DOMDocument();
            $document->loadXML($xml);
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame('Manual Operational Status', $xpath->query('//x:c[@r="AG1"]')->item(0)->textContent);
            $this->assertSame('Pending', $xpath->query('//x:c[@r="AG2"]')->item(0)->textContent);
            $this->assertSame('', $xpath->query('//x:c[@r="AG3"]')->item(0)->textContent);
            $this->assertSame('A1:AG3', $xpath->query('/x:worksheet/x:autoFilter')->item(0)->getAttribute('ref'));
        } finally {
            @unlink($path);
        }
    }

    public function test_invalid_overrides_and_undated_new_completed_records_are_rejected(): void
    {
        foreach (['Unknown', ['Pending'], false, 5] as $manual) {
            $this->api(['action' => 'saveProject', 'record' => ['title' => 'Invalid override', 'approvedBudget' => '1000', 'manualStatus' => $manual]])->assertStatus(422);
        }
        $this->api(['action' => 'saveProject', 'record' => ['title' => 'Undated completed', 'approvedBudget' => '1000', 'status' => 'Pending', 'manualStatus' => 'Completed']])
            ->assertStatus(422)->assertJsonPath('message', 'Completed projects require a valid Completion Date.');
        $record = $this->create(['manualStatus' => 'Completed', 'completion' => '2026-10-09']);
        $this->assertSame('Completed', $record['status']);
    }

    public function test_override_requires_normal_project_edit_permission(): void
    {
        $record = $this->create();
        $this->session['tracker_user'] = ['id' => 2];
        $record['manualStatus'] = 'Released';
        $this->api(['action' => 'saveProject', 'record' => $record])->assertForbidden();
        $this->api(['projectId' => $record['id'], 'record' => json_encode($record), 'deleteIds' => '[]'], 'project-edit.php')->assertForbidden();
        $this->assertSame('Pending', DB::table('projects')->where('id', $record['id'])->value('project_status'));
    }

    public function test_alert_only_permission_is_checked_before_derived_status_changes(): void
    {
        DB::table('users')->where('id', 2)->update(['can_resolve_alerts' => true]);
        $record = ['title' => 'Awaiting scheduler', 'status' => 'Pending', 'manualStatus' => null, 'contractEndDate' => '2026-10-01'];
        $id = DB::table('projects')->insertGetId(['data' => json_encode($record)] + \App\Http\Controllers\TrackerDataController::projectColumns($record));
        $record['id'] = $id;
        $this->session['tracker_user'] = ['id' => 2];
        $this->api(['action' => 'saveProject', 'record' => $record])->assertOk()->assertJsonPath('record.status', 'Final-inspection');
    }
}
