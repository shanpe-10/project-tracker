<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSessionsTest extends TestCase
{
    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL required.');
        if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'account_sessions_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        foreach (glob(database_path('migrations/*.php')) as $file) (require $file)->up();
        foreach ([1 => 'staff', 2 => 'admin', 3 => 'superadmin'] as $id => $role) {
            DB::table('users')->insert(['id' => $id, 'username' => 'session'.$id, 'full_name' => 'Session '.$id,
                'role' => $role, 'active' => true, 'can_edit' => true, 'password_hash' => Hash::make('Test-password')]);
        }
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

    private function login(int $id = 1): array
    {
        app('session')->flush();
        $this->postJson('/api/auth.php', ['action' => 'login', 'username' => 'session'.$id, 'password' => 'Test-password'])
            ->assertOk()->assertJsonPath('ok', true);
        return app('session')->all();
    }

    private function auth(array $session, string $action, array $extra = [])
    {
        return $this->withSession($session)->withHeader('X-CSRF-Token', $session['tracker_csrf'])
            ->postJson('/api/auth.php', ['action' => $action, ...$extra]);
    }

    public function test_new_login_revokes_old_browser_on_reads_saves_and_auth_endpoints(): void
    {
        $old = $this->login(2);
        $new = $this->login(2);
        $this->assertNotSame($old['tracker_session_token'], $new['tracker_session_token']);
        $this->assertSame(1, DB::table('tracker_account_sessions')->count());
        $this->assertSame(hash('sha256', $new['tracker_session_token']), DB::table('tracker_account_sessions')->value('token_hash'));
        foreach (['/api/data.php', '/api/profile.php', '/api/backups.php', '/api/project-presence.php?projectIds[]=1'] as $url) {
            $this->withSession($old)->getJson($url)->assertUnauthorized()->assertJsonPath('reason', 'session_replaced');
        }
        $this->withSession($old)->withHeader('X-CSRF-Token', $old['tracker_csrf'])->postJson('/api/data.php',
            ['action' => 'saveProject', 'record' => ['title' => 'Must not save']])->assertUnauthorized();
        $this->withSession($old)->withHeader('X-CSRF-Token', $old['tracker_csrf'])->post('/api/project-edit.php',
            ['projectId' => 1, 'record' => '{}'])->assertUnauthorized();
        $this->auth($old, 'listAuditLog')->assertUnauthorized();
        $this->auth($new, 'session')->assertOk()->assertJsonPath('authenticated', true);
        $this->assertSame(0, DB::table('projects')->count());
    }

    public function test_old_logout_cannot_sign_out_new_session_and_new_logout_does_revoke_it(): void
    {
        $old = $this->login(); $new = $this->login();
        $this->auth($old, 'logout')->assertUnauthorized();
        $this->auth($new, 'session')->assertJsonPath('authenticated', true);
        $this->auth($new, 'logout')->assertOk();
        $this->withSession($new)->getJson('/api/data.php')->assertUnauthorized();
    }

    public function test_failed_login_and_other_accounts_do_not_revoke_the_current_session(): void
    {
        $session = $this->login();
        $this->postJson('/api/auth.php', ['action' => 'login', 'username' => 'session1', 'password' => 'wrong'])->assertUnauthorized();
        $this->login(2);
        $this->auth($session, 'session')->assertJsonPath('authenticated', true);
    }

    public function test_background_reads_and_session_checks_do_not_extend_inactivity(): void
    {
        foreach ([1, 2] as $id) {
            Carbon::setTestNow();
            $session = $this->login($id);
            $started = now()->getTimestamp();
            Carbon::setTestNow(now()->addMinutes(29));
            $this->withSession($session)->getJson('/api/data.php?action=projectVersion')->assertOk();
            $this->auth($session, 'session')->assertJsonPath('authenticated', true);
            $this->assertSame($started, (int) DB::table('tracker_account_sessions')->where('user_id', $id)->value('last_activity_at'));
            Carbon::setTestNow(now()->addMinute());
            $this->auth($session, 'session')->assertJsonPath('authenticated', false)->assertJsonPath('reason', 'session_idle');
        }
    }

    public function test_actual_activity_renews_idle_time_without_renewing_the_absolute_limit(): void
    {
        $session = $this->login(); $started = now()->getTimestamp();
        Carbon::setTestNow(now()->addMinutes(29));
        $this->auth($session, 'activity', ['lastActivity' => PHP_INT_MAX])->assertOk();
        $row = DB::table('tracker_account_sessions')->first();
        $this->assertSame($started, (int) $row->started_at);
        $this->assertSame(now()->getTimestamp(), (int) $row->last_activity_at);
        Carbon::setTestNow(now()->addMinutes(29));
        $this->auth($session, 'session')->assertJsonPath('authenticated', true);
        Carbon::setTestNow(now()->addMinute());
        $this->auth($session, 'activity')->assertUnauthorized()->assertJsonPath('reason', 'session_idle');
    }

    public function test_superadmin_is_exempt_from_idle_logout_but_still_has_one_session_and_eight_hour_limit(): void
    {
        $old = $this->login(3); $session = $this->login(3);
        $this->auth($old, 'listManagedUsers')->assertUnauthorized();
        Carbon::setTestNow(now()->addHours(7));
        $this->auth($session, 'session')->assertJsonPath('authenticated', true)->assertJsonPath('sessionTiming.idleExpiresAt', null);
        $this->auth($session, 'listManagedUsers')->assertOk();
        Carbon::setTestNow(now()->addHour());
        $this->auth($session, 'session')->assertJsonPath('authenticated', false)->assertJsonPath('reason', 'session_expired');
    }

    public function test_activity_requires_csrf_and_expired_sessions_cannot_be_revived(): void
    {
        $session = $this->login(); $started = now()->getTimestamp();
        Carbon::setTestNow(now()->addMinutes(29));
        $this->withSession($session)->withHeader('X-CSRF-Token', 'wrong')->postJson('/api/auth.php', ['action' => 'activity'])->assertStatus(419);
        $this->assertSame($started, (int) DB::table('tracker_account_sessions')->value('last_activity_at'));
        Carbon::setTestNow(now()->addMinute());
        $this->auth($session, 'activity')->assertUnauthorized()->assertJsonPath('reason', 'session_idle');
    }

    public function test_same_session_tabs_work_and_unsigned_legacy_sessions_require_sign_in(): void
    {
        $session = $this->login();
        $this->auth($session, 'session')->assertJsonPath('authenticated', true);
        $this->withSession($session)->getJson('/api/data.php')->assertOk();
        $legacy = $session; $legacy['tracker_session_token'] = '';
        $this->withSession($legacy)->getJson('/api/data.php')->assertUnauthorized();
        $this->auth($session, 'session')->assertJsonPath('authenticated', true);
    }

    public function test_idle_exemption_uses_the_database_role_not_the_browser_payload(): void
    {
        $session = $this->login();
        $session['tracker_user']['role'] = 'superadmin';
        Carbon::setTestNow(now()->addMinutes(30));
        $this->withSession($session)->getJson('/api/data.php')->assertUnauthorized()->assertJsonPath('reason', 'session_idle');
    }

    public function test_recent_admin_activity_cannot_extend_the_eight_hour_maximum(): void
    {
        $session = $this->login(2);
        Carbon::setTestNow(now()->addHours(8));
        DB::table('tracker_account_sessions')->where('user_id', 2)->update(['last_activity_at' => now()->getTimestamp()]);
        $this->auth($session, 'activity')->assertUnauthorized()->assertJsonPath('reason', 'session_expired');
    }

    public function test_new_login_removes_old_editing_presence_without_removing_other_accounts(): void
    {
        $this->login();
        $projectId = DB::table('projects')->insertGetId(['data' => json_encode(['title' => 'Presence fixture'])]);
        foreach ([1, 2] as $id) DB::table('project_editing_presence')->insert([
            'user_id' => $id, 'project_id' => $projectId, 'editor_token' => str_repeat((string) $id, 32),
            'session_key' => str_repeat((string) $id, 64), 'last_seen_at' => now(),
        ]);
        $this->login();
        $this->assertSame(0, DB::table('project_editing_presence')->where('user_id', 1)->count());
        $this->assertSame(1, DB::table('project_editing_presence')->where('user_id', 2)->count());
    }
}
