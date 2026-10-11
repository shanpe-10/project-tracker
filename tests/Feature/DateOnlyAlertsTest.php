<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use App\Support\TrackerMilestones;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DateOnlyAlertsTest extends TestCase
{
    private ?string $schema = null;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL integration test.');
        if (!str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'date_only_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        DB::table('users')->insert(['id'=>1, 'username'=>'datefixture', 'full_name'=>'Date Fixture', 'role'=>'staff', 'active'=>true,
            'can_create'=>true, 'can_edit'=>true, 'can_import'=>true, 'can_resolve_alerts'=>true, 'password_hash'=>'fixture']);
        $this->session = ['tracker_user'=>['id'=>1], 'tracker_csrf'=>'date-only-token', 'tracker_created_at'=>time(), 'tracker_last_activity'=>time()];
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
        return $this->withSession($this->session)->withHeader('X-CSRF-Token', 'date-only-token')->postJson('/api/'.$endpoint, $body);
    }

    private function project(array $extra = []): array
    {
        $data = $extra + ['title'=>'Date-only fixture', 'projectNo'=>'1', 'approvedBudget'=>'1000', 'status'=>'On-going Bidding', 'updatedAt'=>'2026-10-09T00:00:00.000000Z'];
        $id = DB::table('projects')->insertGetId(['data'=>json_encode($data)] + TrackerDataController::projectColumns($data));
        return $data + ['id'=>$id];
    }

    public function test_every_manual_alert_type_is_rejected_on_regular_and_attachment_saves_without_writes(): void
    {
        $record = $this->project();
        $before = DB::table('projects')->value('data');
        foreach (['noa-warning','noa-active','noa-overdue','noa-due','noa-due-overdue','ntp-award','ntp-overdue','ntp-due','ntp-due-overdue',
            'pre-inspection','pre-inspection-overdue','material-test','material-overdue','final-inspection','final-inspection-overdue','award-close','award-overdue','invented-type'] as $type) {
            $incoming = array_replace($record, ['timelineAlertsCompleted'=>[$type]]);
            $this->api(['action'=>'saveProject', 'record'=>$incoming])->assertUnprocessable()->assertJsonValidationErrors('timelineAlertsCompleted');
            $this->api(['projectId'=>$record['id'], 'record'=>json_encode($incoming), 'deleteIds'=>'[]'], 'project-edit.php')->assertUnprocessable()->assertJsonValidationErrors('timelineAlertsCompleted');
        }
        $this->assertSame($before, DB::table('projects')->value('data'));
        $this->assertSame(0, DB::table('audit_log')->count());
        $this->assertSame(0, DB::table('history')->count());
    }

    public function test_old_individual_bulk_and_global_clients_cannot_manually_complete_alerts(): void
    {
        $record = $this->project();
        foreach ([['timelineAlertsCompleted'=>['noa-overdue']], ['timelineAlertsCompleted'=>['noa-active','ntp-overdue']], ['timelineCompletedMarked'=>true]] as $flags) {
            $this->api(['action'=>'saveTimelineRecord', 'record'=>array_replace($record, $flags),
                'history'=>['action'=>'Legacy completion', 'details'=>'Stale client']])->assertUnprocessable();
        }
        $this->api(['action'=>'saveProject', 'record'=>array_replace($record, ['timelineCompletedMarked'=>true])])->assertUnprocessable()->assertJsonValidationErrors('timelineCompletedMarked');
        $this->api(['projectId'=>$record['id'], 'record'=>json_encode(array_replace($record, ['timelineCompletedMarked'=>true]))], 'project-edit.php')->assertUnprocessable()->assertJsonValidationErrors('timelineCompletedMarked');
        $this->api(['action'=>'saveProject', 'record'=>['title'=>'New flagged project','approvedBudget'=>'100','timelineAlertsCompleted'=>['noa-active']]])->assertUnprocessable();
        $this->assertSame(1, DB::table('projects')->count());
        $this->assertSame(0, DB::table('history')->count());
        $this->assertSame(0, DB::table('audit_log')->count());
    }

    public function test_historical_resolution_flags_survive_edits_and_can_be_restored_but_not_readded(): void
    {
        $record = $this->project(['timelineAlertsCompleted'=>['noa-overdue','ntp-overdue'], 'timelineMarkedDoneDate'=>'2026-10-08T00:00:00Z']);
        $record = $this->api(['action'=>'saveProject', 'record'=>array_replace($record, ['title'=>'Historical flags retained'])])->assertOk()->assertJsonPath('record.timelineAlertsCompleted', ['noa-overdue','ntp-overdue'])->json('record');
        $record = $this->api(['projectId'=>$record['id'], 'record'=>json_encode(array_replace($record, ['remarks'=>'Attachment path also retains flags']))], 'project-edit.php')->assertOk()->assertJsonPath('record.timelineAlertsCompleted', ['noa-overdue','ntp-overdue'])->json('record');
        $record = $this->api(['action'=>'saveTimelineRecord', 'record'=>array_replace($record, ['timelineAlertsCompleted'=>['ntp-overdue']]),
            'history'=>['action'=>'Restore historical alert', 'details'=>'Reopened NOA']])->assertOk()->assertJsonPath('record.timelineAlertsCompleted', ['ntp-overdue'])->json('record');
        $this->assertSame(2, DB::table('history')->count()); // Attachment edit and atomic restore.
        $this->api(['action'=>'saveTimelineRecord', 'record'=>array_replace($record, ['timelineAlertsCompleted'=>['ntp-overdue','noa-overdue']]),
            'history'=>['action'=>'Re-add flag']])->assertUnprocessable();
        $this->assertSame(2, DB::table('history')->count());
    }

    public function test_historical_global_flags_can_be_restored_and_permission_is_required(): void
    {
        $record = $this->project(['timelineCompletedMarked'=>true]);
        DB::table('users')->where('id',1)->update(['can_resolve_alerts'=>false]);
        $this->api(['action'=>'saveTimelineRecord', 'record'=>array_replace($record, ['timelineCompletedMarked'=>false]), 'history'=>['action'=>'Restore global flag']])->assertForbidden();
        $this->api(['action'=>'saveProject', 'record'=>array_replace($record, ['timelineCompletedMarked'=>false])])->assertForbidden();
        DB::table('users')->where('id',1)->update(['can_resolve_alerts'=>true, 'can_edit'=>false]);
        $this->api(['action'=>'saveTimelineRecord', 'record'=>array_replace($record, ['timelineCompletedMarked'=>false]), 'history'=>['action'=>'Restore global flag']])->assertOk()->assertJsonPath('record.timelineCompletedMarked', false);
    }

    public function test_actual_dates_resolve_legacy_noa_and_ntp_and_date_based_alerts_without_resolve_permission(): void
    {
        DB::table('users')->where('id',1)->update(['can_resolve_alerts'=>false]);
        $record = $this->project(['biddingDate'=>'2026-09-01', 'contractEndDate'=>'2026-10-01']);
        $record = $this->api(['action'=>'saveProject', 'record'=>array_replace($record, ['noa'=>'2026-10-09', 'ntpDate'=>'2026-10-09', 'preInspection'=>'2026-10-09', 'materialTestingDate'=>'2026-10-09', 'finalInspection'=>'2026-10-09'])])->assertOk()->assertJsonPath('record.status', 'Pending')->json('record');
        $this->withSession($this->session)->getJson('/api/data.php?action=projectAlerts&projectId='.$record['id'])->assertOk()
            ->assertJsonFragment(['label'=>'Notice of Award','status'=>'Resolved','doneDate'=>'2026-10-09'])
            ->assertJsonFragment(['label'=>'Notice to Proceed','status'=>'Resolved','doneDate'=>'2026-10-09'])
            ->assertJsonFragment(['label'=>'Final Inspection','status'=>'Resolved','doneDate'=>'2026-10-09']);
        $this->assertArrayNotHasKey('timelineAlertsCompleted', $record);
        $today = new \DateTimeImmutable('2026-10-10');
        $resolutions = TrackerMilestones::automaticResolutions(['noa'=>'2026-10-09','ntpDate'=>'2026-10-09'], $today);
        $this->assertCount(2, $resolutions);
        $this->assertContains('noa-overdue', $resolutions[0]['aliases']);
        $this->assertContains('ntp-overdue', $resolutions[1]['aliases']);
        $this->assertSame([], TrackerMilestones::automaticResolutions(['noa'=>'2026-10-12','ntpDate'=>'2026-10-12','finalInspection'=>'2026-10-12'], $today));
    }

    public function test_imported_manual_flags_are_stripped(): void
    {
        $this->api(['action'=>'bulkSaveProjects','batchId'=>'date_only_flags','records'=>[['title'=>'Imported date-only project','timelineAlertsCompleted'=>['noa-overdue','ntp-overdue'],'timelineCompletedMarked'=>true]]])->assertOk();
        $record = json_decode(DB::table('projects')->value('data'), true);
        $this->assertArrayNotHasKey('timelineAlertsCompleted', $record);
        $this->assertArrayNotHasKey('timelineCompletedMarked', $record);
    }
}
