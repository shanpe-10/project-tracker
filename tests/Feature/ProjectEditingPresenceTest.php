<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectEditingPresenceTest extends TestCase
{
    private ?string $schema = null;
    private array $session;
    private int $projectId;
    private const TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TOKEN_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL integration test.');
        if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'presence_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        foreach ([1 => 'staff', 2 => 'admin', 3 => 'staff', 4 => 'superadmin'] as $id => $role) {
            DB::table('users')->insert(['id' => $id, 'username' => 'editor'.$id, 'full_name' => 'Editor '.$id,
                'role' => $role, 'active' => true, 'can_edit' => $id !== 3, 'password_hash' => 'fixture']);
        }
        $data = ['title' => 'Presence project', 'projectNo' => '1', 'status' => 'Pending', 'approvedBudget' => '1000', 'updatedAt' => '2026-10-01T00:00:00.000000Z'];
        $this->projectId = DB::table('projects')->insertGetId(['data' => json_encode($data)] + TrackerDataController::projectColumns($data));
        $this->asUser(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->schema) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    private function asUser(int $id): void
    {
        $this->session = ['tracker_user' => ['id' => $id], 'tracker_csrf' => 'presence-token',
            'tracker_created_at' => time(), 'tracker_last_activity' => time()];
    }

    private function postPresence(string $action = 'heartbeat', string $token = self::TOKEN_A, ?int $projectId = null)
    {
        return $this->authenticated()->withHeader('X-CSRF-Token', 'presence-token')->postJson('/api/project-presence.php', [
            'action' => $action, 'projectId' => $projectId ?? $this->projectId, 'editorToken' => $token,
        ]);
    }

    private function readPresence()
    {
        return $this->authenticated()->getJson('/api/project-presence.php?projectIds[]='.$this->projectId);
    }

    private function authenticated(): self
    {
        $this->withSession($this->session);
        app('session')->save();
        return $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
    }

    private function people(): array
    {
        return $this->readPresence()->assertOk()->json('editorsByProject.'.$this->projectId);
    }

    public function test_presence_requires_authentication_csrf_and_edit_permission(): void
    {
        $this->getJson('/api/project-presence.php?projectIds[]=1')->assertUnauthorized();
        $this->authenticated()->postJson('/api/project-presence.php', [
            'action' => 'heartbeat', 'projectId' => $this->projectId, 'editorToken' => self::TOKEN_A,
        ])->assertStatus(419);
        $this->asUser(3);
        $this->postPresence()->assertForbidden();
        $this->assertSame([], $this->people());
        $this->asUser(4);
        $this->readPresence()->assertForbidden();
        $this->postPresence()->assertForbidden();
        $this->assertSame(0, DB::table('project_editing_presence')->count());
    }

    public function test_tracker_csrf_works_with_framework_csrf_checks_enabled(): void
    {
        // Laravel skips framework CSRF in the normal testing environment. Exercise production behavior.
        $this->app['env'] = 'local';
        $this->postPresence()->assertOk()->assertJsonPath('editorsByProject.'.$this->projectId.'.0.id', 1);
        $this->authenticated()->withHeader('X-CSRF-Token', '')->postJson('/api/project-presence.php', [
            'action'=>'heartbeat', 'projectId'=>$this->projectId, 'editorToken'=>self::TOKEN_B,
        ])->assertStatus(419);
        $this->authenticated()->withHeader('X-CSRF-Token', 'incorrect-token')->postJson('/api/project-presence.php', [
            'action'=>'heartbeat', 'projectId'=>$this->projectId, 'editorToken'=>self::TOKEN_B,
        ])->assertStatus(419);
        $this->postPresence('stop')->assertOk();
        $this->assertSame([], $this->people());
        $this->assertSame(0, DB::table('audit_log')->count());
        $this->assertSame(0, DB::table('history')->count());
    }

    public function test_two_people_and_multiple_tabs_are_visible_without_changing_the_project(): void
    {
        $before = DB::table('projects')->where('id', $this->projectId)->first();
        $version = $this->authenticated()->getJson('/api/data.php?action=projectVersion')->json('version');
        $this->postPresence()->assertOk();
        $this->postPresence('heartbeat', self::TOKEN_B)->assertOk();
        $this->asUser(2);
        $this->postPresence()->assertOk();
        $this->assertSame([1, 2], array_column($this->people(), 'id'));
        $this->asUser(1);
        $this->postPresence('stop')->assertOk();
        $this->assertSame([1, 2], array_column($this->people(), 'id'));
        $this->postPresence('stop', self::TOKEN_B)->assertOk();
        $this->assertSame([2], array_column($this->people(), 'id'));
        $this->assertEquals($before, DB::table('projects')->where('id', $this->projectId)->first());
        $this->assertSame($version, $this->authenticated()->getJson('/api/data.php?action=projectVersion')->json('version'));
        $this->assertSame(0, DB::table('history')->count());
        $this->assertSame(0, DB::table('audit_log')->count());
        $response = $this->readPresence()->assertOk()->assertJsonPath('ttlSeconds', 45);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->json('serverTime'));
    }

    public function test_expired_deactivated_and_revoked_editors_disappear(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));
        $this->postPresence()->assertOk();
        Carbon::setTestNow(now()->addSeconds(44));
        $this->assertCount(1, $this->people());
        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertSame([], $this->people());
        $this->postPresence()->assertOk();
        DB::table('users')->where('id', 1)->update(['active' => false]);
        $this->asUser(2);
        $this->assertSame([], $this->people());
        DB::table('users')->where('id', 1)->update(['active' => true, 'can_edit' => false]);
        $this->assertSame([], $this->people());
    }

    public function test_stop_before_or_after_a_delayed_heartbeat_cannot_revive_a_closed_editor(): void
    {
        $this->postPresence('stop')->assertOk();
        $this->postPresence()->assertOk();
        $this->assertSame([], $this->people());
        $this->postPresence('heartbeat', self::TOKEN_B)->assertOk();
        $this->assertCount(1, $this->people());
        $this->postPresence('stop', self::TOKEN_B)->assertOk();
        $this->postPresence('heartbeat', self::TOKEN_B)->assertOk();
        $this->assertSame([], $this->people());
    }

    public function test_project_and_session_scope_cannot_be_reused_and_deletion_cascades(): void
    {
        $this->postPresence()->assertOk();
        $otherId = DB::table('projects')->insertGetId(['data' => json_encode(['title' => 'Other'])]);
        $this->postPresence('heartbeat', self::TOKEN_A, $otherId)->assertStatus(409);
        DB::table('project_editing_presence')->where('user_id', 1)->update(['session_key' => str_repeat('f', 64)]);
        $this->postPresence('heartbeat')->assertStatus(409);
        $this->postPresence('stop')->assertStatus(409);
        $this->postPresence('heartbeat', self::TOKEN_B, 999999)->assertNotFound();
        $this->postPresence('stop', self::TOKEN_B, 999999)->assertOk();
        DB::table('projects')->where('id', $this->projectId)->delete();
        $this->assertSame(0, DB::table('project_editing_presence')->count());
    }

    public function test_logout_stops_only_the_current_browser_session(): void
    {
        $this->postPresence()->assertOk();
        DB::table('project_editing_presence')->insert([
            'user_id' => 1, 'project_id' => $this->projectId, 'editor_token' => self::TOKEN_B,
            'session_key' => str_repeat('f', 64), 'last_seen_at' => now(),
        ]);
        $this->authenticated()->withHeader('X-CSRF-Token', 'presence-token')->postJson('/api/auth.php', ['action' => 'logout'])->assertOk();
        $this->assertNotNull(DB::table('project_editing_presence')->where('editor_token', self::TOKEN_A)->value('ended_at'));
        $this->assertNull(DB::table('project_editing_presence')->where('editor_token', self::TOKEN_B)->value('ended_at'));
    }

    public function test_validation_bounds_queries_and_the_migration_matches_the_schema(): void
    {
        $this->authenticated()->getJson('/api/project-presence.php?'.http_build_query(['projectIds' => range(1, 101)]))->assertUnprocessable();
        $this->authenticated()->withHeader('X-CSRF-Token', 'presence-token')->postJson('/api/project-presence.php', [
            'action' => 'heartbeat', 'projectId' => $this->projectId, 'editorToken' => '<bad token>',
        ])->assertUnprocessable();
        Schema::drop('project_editing_presence');
        $migration = require database_path('migrations/2026_10_10_000001_create_project_editing_presence.php');
        $migration->up();
        $this->postPresence()->assertOk();
        $this->assertCount(1, $this->people());
    }
}
