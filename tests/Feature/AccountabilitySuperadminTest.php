<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\{TrackerAccess, TrackerAudit, TrackerDefaultAccounts};
use Illuminate\Support\Facades\{Artisan, DB, Hash};
use Tests\TestCase;

final class AccountabilitySuperadminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('Isolated PostgreSQL required.');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        (require database_path('migrations/2026_11_20_000001_allow_accountability_superadmin.php'))->up();
        $this->useWeekdayCalendarFixture();
    }

    private function fixtures(): void
    {
        foreach ([1 => ['admin', 'manager'], 2 => ['staff', 'staff'], 3 => ['superadmin', 'superadmin']] as $id => [$role, $name]) {
            DB::table('users')->insert(['id' => $id, 'username' => $name, 'full_name' => $name, 'role' => $role,
                'active' => true, 'can_create' => true, 'can_edit' => true, 'can_export' => true,
                'password_hash' => Hash::make('Fixture-password-2026!')]);
        }
        DB::statement("SELECT setval(pg_get_serial_sequence('users','id'),3,true)");
    }

    private function asUser(int $id): self
    {
        $token = 'audit-fixture-session-'.$id;
        DB::table('tracker_account_sessions')->updateOrInsert(['user_id' => $id],
            ['token_hash' => hash('sha256', $token), 'started_at' => time(), 'last_activity_at' => time()]);
        $this->withSession(['tracker_user' => ['id' => $id], 'tracker_csrf' => 'audit-fixture',
            'tracker_created_at' => time(), 'tracker_last_activity' => time(), 'tracker_session_token' => $token]);
        return $this->withHeader('X-CSRF-Token', 'audit-fixture');
    }

    private function auth(array $body)
    {
        return $this->postJson('/api/auth.php', $body);
    }

    public function test_source_defaults_work_once_and_later_password_changes_are_preserved(): void
    {
        $this->assertSame(0, Artisan::call('tracker:initialize-source-accounts'));
        $this->assertDatabaseCount('users', 2);
        $admin = User::where('username', 'admin')->firstOrFail();
        $auditor = User::where('username', 'superadmin')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('admin9876543', $admin->password_hash));
        $this->assertSame('superadmin', $auditor->role);
        $this->assertTrue(Hash::check('superadmin9876543', $auditor->password_hash));
        $this->assertNull($admin->password_encrypted);
        $this->assertSame([], array_filter(TrackerAccess::capabilities($auditor)));
        $replacement = Hash::make('Changed-owner-password-2026!');
        $admin->password_hash = $replacement; $admin->save();
        $this->assertFalse(TrackerDefaultAccounts::initialize());
        $this->assertSame($replacement, $admin->fresh()->password_hash);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_existing_reserved_account_is_converted_without_changing_other_accounts_or_projects(): void
    {
        $this->fixtures();
        DB::table('users')->where('id', 3)->update(['role' => 'admin']);
        $password = DB::table('users')->where('id', 2)->value('password_hash');
        DB::table('projects')->insert(['data' => '{"title":"Retained"}']);
        TrackerDefaultAccounts::initialize();
        $this->assertDatabaseHas('users', ['id' => 3, 'username' => 'superadmin', 'role' => 'superadmin']);
        $this->assertSame($password, DB::table('users')->where('id', 2)->value('password_hash'));
        $this->assertDatabaseHas('users', ['id' => 1, 'username' => 'manager', 'role' => 'admin']);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('users', 4);
    }

    public function test_auditor_can_sign_in_and_only_open_read_only_accountability(): void
    {
        TrackerDefaultAccounts::initialize();
        $this->auth(['action' => 'login', 'username' => 'superadmin', 'password' => 'superadmin9876543'])
            ->assertOk()->assertJsonPath('user.role', 'superadmin')->assertJsonPath('user.canEdit', false);
        $id = User::where('username', 'superadmin')->value('id');
        $this->asUser($id);
        $this->get('/accountability')->assertOk()->assertSee('Read-only access')->assertDontSee('Clear history');
        $this->auth(['action' => 'listAuditLog'])->assertOk()->assertJsonPath('readOnly', true);
        $this->getJson('/api/data.php?action=bootstrap')->assertForbidden();
        $this->postJson('/api/data.php', ['action' => 'saveProject', 'record' => ['title' => 'Forbidden']])->assertForbidden();
        foreach (['listManagedUsers', 'createUser', 'updateUser', 'deleteUser', 'clearAuditLogView'] as $action) {
            $this->auth(['action' => $action, 'confirmation' => 'CLEAR HISTORY'])->assertForbidden();
        }
    }

    public function test_hidden_role_cannot_be_listed_assigned_or_modified_by_regular_admin(): void
    {
        $this->fixtures(); $this->asUser(1);
        $this->auth(['action' => 'listManagedUsers'])->assertOk()->assertJsonCount(2, 'users')
            ->assertJsonMissing(['username' => 'superadmin']);
        $this->auth(['action' => 'createUser', 'username' => 'new.user', 'fullName' => 'New', 'role' => 'superadmin',
            'active' => true, 'password' => 'Fixture-new-password!'])->assertUnprocessable();
        $this->auth(['action' => 'createUser', 'username' => 'SUPERADMIN', 'fullName' => 'New', 'role' => 'admin',
            'active' => true, 'password' => 'Fixture-new-password!'])->assertUnprocessable();
        $this->auth(['action' => 'updateUser', 'id' => 3, 'username' => 'renamed', 'fullName' => 'Renamed', 'role' => 'staff', 'active' => false])->assertNotFound();
        foreach (['deleteUser', 'rejectUser', 'approveUser', 'setUserPermission'] as $action) {
            $this->auth(['action' => $action, 'id' => 3, 'permission' => 'canEdit', 'allowed' => true])->assertNotFound();
        }
        $this->assertDatabaseHas('users', ['id' => 3, 'role' => 'superadmin', 'active' => true]);
        $this->get('/')->assertDontSee('value="superadmin"', false);
    }

    public function test_auditor_sees_preserved_history_and_filters_while_admin_keeps_the_existing_cutoff(): void
    {
        $this->fixtures();
        TrackerAudit::record(['actor_id' => 1, 'actor_username' => 'manager', 'action' => 'project.saved', 'entity_type' => 'project',
            'entity_id' => null, 'summary' => 'Old road project edit', 'created_at' => now()->subDays(5)]);
        TrackerAudit::record(['actor_id' => 2, 'actor_username' => 'staff', 'action' => 'project.saved', 'entity_type' => 'project',
            'entity_id' => null, 'summary' => 'Other activity', 'created_at' => now()]);
        DB::table('application_settings')->insert(['setting_key' => 'audit_visible_after', 'setting_value' => json_encode(now()->addDay()->format('Y-m-d H:i:s'))]);
        $this->asUser(3)->auth(['action' => 'listAuditLog', 'actor' => 'manager', 'search' => 'road', 'actionFilter' => 'project.saved'])
            ->assertOk()->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.summary', 'Old road project edit');
        $this->asUser(1)->auth(['action' => 'listAuditLog'])->assertOk()->assertJsonCount(0, 'entries');
        $this->asUser(2)->auth(['action' => 'listAuditLog'])->assertForbidden();
    }

    public function test_project_details_record_actual_before_and_after_values_with_actor_and_target(): void
    {
        $this->fixtures(); $this->asUser(1);
        $record = $this->postJson('/api/data.php', ['action' => 'saveProject', 'record' => ['title' => 'Before title', 'approvedBudget' => '1000']])->assertOk()->json('record');
        $record['title'] = 'After title'; $record['approvedBudget'] = '1200';
        $this->postJson('/api/data.php', ['action' => 'saveProject', 'record' => $record])->assertOk();
        $id = DB::table('audit_log')->where('action', 'project.saved')->orderByDesc('id')->value('id');
        $details = $this->asUser(3)->auth(['action' => 'getAuditLogEntry', 'id' => $id])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('entry.metadata.audit.actor.role', 'admin')->json('entry.metadata.audit');
        $title = collect($details['changes'])->firstWhere('field', 'title');
        $this->assertSame('Before title', $title['before']); $this->assertSame('After title', $title['after']);
        $this->assertSame($record['projectNo'], $details['target']['projectNo']);
        $this->asUser(1)->auth(['action' => 'getAuditLogEntry', 'id' => $id])->assertForbidden();
    }

    public function test_credentials_are_excluded_and_auditor_activity_is_hidden_from_admin_views(): void
    {
        $this->fixtures();
        TrackerAudit::record(['actor_id' => 3, 'actor_username' => 'superadmin', 'action' => 'account.signed_in', 'entity_type' => 'account',
            'entity_id' => 3, 'summary' => 'Private auditor activity', 'created_at' => now(),
            'metadata' => json_encode(['password' => 'Private-value', 'csrfToken' => 'Private-token'])],
            ['before' => ['password_hash' => 'Private-hash'], 'after' => ['username' => 'superadmin']]);
        $id = DB::table('audit_log')->value('id');
        $this->asUser(3)->auth(['action' => 'getAuditLogEntry', 'id' => $id])->assertOk()
            ->assertDontSee('Private-value')->assertDontSee('Private-token')->assertDontSee('Private-hash');
        $this->asUser(1)->auth(['action' => 'listAuditLog'])->assertOk()->assertJsonCount(0, 'entries');
        $this->asUser(3)->auth(['action' => 'listAuditLog'])->assertOk()->assertJsonCount(1, 'entries');
    }
}
