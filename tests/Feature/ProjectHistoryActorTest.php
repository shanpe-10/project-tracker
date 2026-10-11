<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectHistoryActorTest extends TestCase
{
    private ?string $schema = null;
    private int $projectId;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL integration test.');
        if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'history_actor_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        foreach ([1 => 'staff', 2 => 'admin', 3 => 'staff'] as $id => $role) {
            DB::table('users')->insert(['id' => $id, 'username' => 'editor'.$id, 'full_name' => 'Editor '.$id,
                'role' => $role, 'active' => true, 'can_edit' => $id !== 3, 'password_hash' => 'fixture']);
        }
        $data = ['title' => 'History project', 'projectNo' => '1', 'status' => 'On-going Bidding',
            'approvedBudget' => '1000', 'updatedBy' => 'previous-editor', 'updatedAt' => '2026-10-01T00:00:00.000000Z'];
        $this->projectId = DB::table('projects')->insertGetId(['data' => json_encode($data)] + TrackerDataController::projectColumns($data));
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

    private function asUser(int $id): void
    {
        $this->session = ['tracker_user' => ['id' => $id], 'tracker_csrf' => 'history-token',
            'tracker_created_at' => time(), 'tracker_last_activity' => time()];
    }

    private function authenticated(): self
    {
        $this->withSession($this->session);
        app('session')->save();
        return $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId())
            ->withHeader('X-CSRF-Token', 'history-token');
    }

    private function project(): array
    {
        return json_decode(DB::table('projects')->where('id', $this->projectId)->value('data'), true);
    }

    private function edit(string $title)
    {
        $record = array_replace($this->project(), ['title' => $title, 'updatedBy' => 'forged-editor',
            'actor' => ['id' => 999, 'username' => 'fake', 'fullName' => 'Fake User']]);
        return $this->authenticated()->postJson('/api/project-edit.php', [
            'projectId' => $this->projectId, 'record' => json_encode($record), 'deleteIds' => '[]',
        ]);
    }

    private function history(int $page = 1): array
    {
        return $this->authenticated()->getJson('/api/data.php?action=projectHistory&projectId='.$this->projectId.'&pageSize=10&page='.$page)
            ->assertOk()->json('history');
    }

    public function test_actual_edit_logs_each_authenticated_editor_not_the_previous_snapshot_author(): void
    {
        // Framework CSRF is normally skipped by PHPUnit; exercise the production middleware as well.
        $this->app['env'] = 'local';
        $this->edit('First change')->assertOk();
        $this->assertArrayNotHasKey('actor', $this->project());
        $first = $this->history()[0];
        $this->assertEquals(['id' => 1, 'username' => 'editor1', 'fullName' => 'Editor 1'], $first['actor']);
        $this->assertSame('previous-editor', json_decode($first['changes'], true)['updatedBy']);
        $this->asUser(2);
        $this->edit('Second change')->assertOk();
        $logs = $this->history();
        $this->assertCount(2, $logs);
        $this->assertEquals(['id' => 2, 'username' => 'editor2', 'fullName' => 'Editor 2'], $logs[0]['actor']);
        $this->assertSame('Editor 1', $logs[1]['actor']['fullName']);
        $this->assertSame(2, (int) DB::table('history')->orderByDesc('id')->value('updated_by'));
        $this->assertSame('First change', json_decode($logs[0]['changes'], true)['title']);
    }

    public function test_saved_identity_survives_account_rename_and_deletion(): void
    {
        $this->edit('Recorded change')->assertOk();
        DB::table('users')->where('id', 1)->update(['full_name' => 'Renamed person', 'username' => 'renamed-editor']);
        $this->asUser(2);
        $this->assertEquals(['id' => 1, 'username' => 'editor1', 'fullName' => 'Editor 1'], $this->history()[0]['actor']);
        DB::table('users')->where('id', 1)->delete();
        $this->assertNull(DB::table('history')->value('updated_by'));
        $this->assertEquals(['id' => 1, 'username' => 'editor1', 'fullName' => 'Editor 1'], $this->history()[0]['actor']);
    }

    public function test_client_cannot_forge_the_actor_when_saving_history(): void
    {
        $this->authenticated()->postJson('/api/data.php', ['action' => 'saveHistory', 'record' => [
            'projectId' => $this->projectId, 'editedAt' => '2026-10-10T12:00:00Z', 'updatedBy' => 'fake',
            'action' => 'Historical change', 'actor' => ['id' => 2, 'username' => 'editor2', 'fullName' => 'Editor 2'],
        ]])->assertOk()->assertJsonPath('record.actor.id', 1)->assertJsonPath('record.actor.fullName', 'Editor 1');
        $this->assertSame('editor1', $this->history()[0]['updatedBy']);
        $this->assertSame(1, $this->history()[0]['actor']['id']);
    }

    public function test_archive_restore_and_timeline_logs_include_the_actor(): void
    {
        $this->asUser(2);
        foreach ([true, false] as $archived) {
            $this->authenticated()->postJson('/api/data.php', ['action' => 'setProjectArchived',
                'id' => $this->projectId, 'archived' => $archived])->assertOk();
            $this->assertSame('Editor 2', $this->history()[0]['actor']['fullName']);
        }
        $record = $this->project();
        $record['timelineAlertsCompleted'] = ['NOA'];
        DB::table('projects')->where('id', $this->projectId)->update(['data' => json_encode($record)]);
        $record['timelineAlertsCompleted'] = [];
        $record['id'] = $this->projectId;
        $this->authenticated()->postJson('/api/data.php', ['action' => 'saveTimelineRecord', 'record' => $record,
            'history' => ['action' => 'Unmark', 'details' => 'Restored historical alert',
                'actor' => ['id' => 1, 'fullName' => 'Wrong user']]])->assertOk();
        $this->assertSame('Editor 2', $this->history()[0]['actor']['fullName']);
        $this->assertCount(3, $this->history());
    }

    public function test_legacy_history_uses_its_own_actor_and_preserves_pagination(): void
    {
        foreach (range(1, 13) as $index) {
            $record = ['projectId' => $this->projectId, 'editedAt' => '2026-10-10T12:00:00Z',
                'changes' => json_encode(['updatedBy' => 'wrong-snapshot-author'])];
            $actorId = $index < 12 ? 2 : null;
            if ($index === 12) $record['updatedBy'] = 'deleted-legacy-user';
            DB::table('history')->insert(['project_id' => $this->projectId, 'data' => json_encode($record),
                'event_at' => '2026-10-10 12:00:00', 'updated_by' => $actorId]);
        }
        $this->asUser(3);
        $logs = $this->history();
        $this->assertCount(10, $logs);
        $this->assertEquals(['id' => null, 'username' => '', 'fullName' => ''], $logs[0]['actor']);
        $this->assertEquals(['id' => null, 'username' => 'deleted-legacy-user', 'fullName' => ''], $logs[1]['actor']);
        $this->assertEquals(['id' => 2, 'username' => 'editor2', 'fullName' => 'Editor 2'], $logs[2]['actor']);
        $this->assertCount(3, $this->history(2));
        $this->assertSame(13, DB::table('history')->count());
    }

    public function test_failed_or_unauthorized_edits_do_not_add_history(): void
    {
        $this->asUser(3);
        $this->edit('Not allowed')->assertForbidden();
        $this->assertEquals([], $this->history());
        $this->asUser(1);
        $record = array_replace($this->project(), ['updatedAt' => '2026-09-01T00:00:00Z']);
        $this->authenticated()->postJson('/api/project-edit.php', ['projectId' => $this->projectId,
            'record' => json_encode($record)])->assertStatus(409);
        $this->assertEquals([], $this->history());
    }
}
