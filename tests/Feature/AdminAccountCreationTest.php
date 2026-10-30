<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAccountCreationTest extends TestCase
{
    private ?string $schema = null;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
            $this->schema = 'admin_creation_'.bin2hex(random_bytes(6));
            DB::statement('CREATE SCHEMA "'.$this->schema.'"');
            DB::statement('SET search_path TO "'.$this->schema.'"');
            DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        } else $this->sqliteSchema();
        foreach ([1 => 'superadmin', 2 => 'admin', 3 => 'staff'] as $id => $role) {
            DB::table('users')->insert(['id' => $id, 'username' => 'creator'.$id, 'full_name' => 'Creator '.$id, 'role' => $role,
                'active' => true, 'password_hash' => Hash::make('fixture-creator-password')]);
            DB::table('tracker_account_sessions')->insert(['user_id' => $id, 'token_hash' => hash('sha256', 'creator-session-'.$id), 'started_at' => time(), 'last_activity_at' => time()]);
        }
        if ($this->schema) DB::statement("SELECT setval(pg_get_serial_sequence('users','id'), 3, true)");
        $this->asUser(2);
    }

    protected function tearDown(): void
    {
        if ($this->schema) { DB::statement('SET search_path TO public'); DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE'); }
        parent::tearDown();
    }

    private function sqliteSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('username')->unique(); $t->text('password_encrypted')->nullable();
            foreach (['full_name', 'role', 'password_hash'] as $name) $t->string($name);
            $t->boolean('active')->default(true); $t->timestamp('created_at')->useCurrent();
            foreach (['can_create','can_edit','can_import','can_export','can_delete','can_delete_all','can_resolve_alerts','can_archive'] as $name) $t->boolean($name)->default(false);
        });
        Schema::create('tracker_account_sessions', function (Blueprint $t) { $t->unsignedBigInteger('user_id')->primary(); $t->string('token_hash'); $t->bigInteger('started_at'); $t->bigInteger('last_activity_at'); });
        Schema::create('project_editing_presence', function (Blueprint $t) { $t->id(); $t->bigInteger('user_id'); $t->string('session_key')->nullable(); $t->timestamp('ended_at')->nullable(); });
        Schema::create('application_settings', function (Blueprint $t) { $t->string('setting_key')->primary(); $t->json('setting_value'); $t->timestamp('updated_at')->nullable(); });
        Schema::create('audit_log', function (Blueprint $t) { $t->id(); $t->bigInteger('actor_id')->nullable(); foreach (['actor_username','action','entity_type','summary'] as $name) $t->string($name); $t->bigInteger('entity_id')->nullable(); $t->json('metadata')->nullable(); $t->timestamp('created_at'); });
        Schema::create('login_attempts', function (Blueprint $t) { $t->string('attempt_key')->primary(); $t->integer('failed_count'); $t->timestamp('first_failed_at'); $t->timestamp('locked_until')->nullable(); $t->timestamp('updated_at'); });
    }

    private function asUser(int $id): void
    {
        $this->session = ['tracker_user' => ['id' => $id], 'tracker_csrf' => 'creator-csrf', 'tracker_session_token' => 'creator-session-'.$id];
    }

    private function authenticated(): self
    {
        $this->withSession($this->session); app('session')->save();
        return $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId())->withHeader('X-CSRF-Token', 'creator-csrf');
    }

    private function api(array $data)
    {
        return $this->authenticated()->postJson('/api/auth.php', $data);
    }

    private function account(array $overrides = []): array
    {
        return $overrides + ['action' => 'createUser', 'username' => 'created.staff', 'fullName' => 'Created Staff', 'password' => 'valid-account-password', 'role' => 'staff', 'active' => true];
    }

    public function test_superadmin_creates_a_staff_account_with_hashed_password_view_only_access_and_audit(): void
    {
        $this->asUser(1);
        $this->api($this->account(['canEdit' => true, 'canDelete' => true, 'can_create' => true]))->assertOk()->assertJsonPath('message', 'Account created.');
        $row = DB::table('users')->where('username', 'created.staff')->first();
        $this->assertSame('staff', $row->role); $this->assertTrue((bool) $row->active);
        $this->assertTrue(Hash::check('valid-account-password', $row->password_hash));
        foreach (['can_edit', 'can_delete', 'can_create'] as $permission) $this->assertFalse((bool) $row->{$permission});
        $this->assertDatabaseHas('audit_log', ['actor_id' => 1, 'action' => 'account.created', 'entity_id' => $row->id]);
    }

    public function test_admin_cannot_create_any_role_even_with_a_valid_authenticated_session(): void
    {
        foreach (['staff', 'admin', 'superadmin'] as $role) $this->api($this->account(['role' => $role]))->assertForbidden();
        $this->assertDatabaseCount('users', 3);
        $this->assertSame(0, DB::table('audit_log')->where('action', 'account.created')->count());
    }

    public function test_admin_cannot_create_a_superadmin_or_change_existing_accounts(): void
    {
        $this->api($this->account(['role' => 'superadmin']))->assertForbidden();
        foreach (['listManagedUsers','updateUser','setUserPermission','approveUser','rejectUser','deleteUser'] as $action) {
            $this->api($this->account(['action' => $action, 'id' => 3, 'permission' => 'canEdit', 'allowed' => true]))->assertForbidden();
        }
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseHas('users', ['id' => 3, 'role' => 'staff', 'active' => true]);
    }

    public function test_staff_and_unauthenticated_visitors_cannot_create_accounts(): void
    {
        $this->postJson('/api/auth.php', $this->account())->assertUnauthorized();
        $this->asUser(3); $this->session['tracker_user']['role'] = 'superadmin';
        $this->api($this->account())->assertForbidden();
        $this->api(['action' => 'register'])->assertForbidden();
        $this->assertDatabaseCount('users', 3);
    }

    public function test_csrf_inactive_accounts_and_role_changes_are_checked_on_the_server(): void
    {
        $this->authenticated()->withHeader('X-CSRF-Token', 'wrong')->postJson('/api/auth.php', $this->account())->assertStatus(419);
        DB::table('users')->where('id', 2)->update(['role' => 'staff']);
        $this->api($this->account())->assertForbidden();
        DB::table('users')->where('id', 2)->update(['role' => 'admin', 'active' => false]);
        $this->api($this->account())->assertUnauthorized();
        $this->assertDatabaseCount('users', 3);
    }

    public function test_superadmin_creation_retains_validation_and_duplicate_username_checks(): void
    {
        $this->asUser(1);
        foreach ([['password' => 'short'], ['username' => 'bad username'], ['fullName' => ''], ['role' => 'owner'], ['active' => 'invalid']] as $invalid) $this->api($this->account($invalid))->assertUnprocessable();
        $this->api($this->account())->assertOk();
        $this->api($this->account())->assertUnprocessable();
        $this->assertDatabaseCount('users', 4);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'account.created')->count());
    }

    public function test_superadmin_retains_all_role_creation_and_existing_account_management(): void
    {
        $this->asUser(1);
        foreach (['staff', 'admin', 'superadmin'] as $role) $this->api($this->account(['username' => 'new.'.$role, 'role' => $role]))->assertOk();
        $this->api($this->account(['action' => 'updateUser', 'id' => 3, 'username' => 'updated.staff', 'fullName' => 'Updated Staff']))->assertOk();
        $this->api(['action' => 'listManagedUsers'])->assertOk()->assertJsonCount(6, 'users');
        $this->assertDatabaseHas('users', ['id' => 3, 'username' => 'updated.staff']);
    }

    public function test_superadmin_can_create_an_inactive_account_but_it_cannot_sign_in(): void
    {
        $this->asUser(1);
        $this->api($this->account(['active' => false]))->assertOk();
        $this->assertDatabaseHas('users', ['username' => 'created.staff', 'active' => false]);
        $this->postJson('/api/auth.php', ['action' => 'login', 'username' => 'created.staff', 'password' => 'valid-account-password'])->assertForbidden();
    }

    public function test_production_csrf_checks_keep_admin_creation_disabled_and_superadmin_creation_available(): void
    {
        $this->app['env'] = 'local';
        $this->api($this->account())->assertForbidden();
        $this->asUser(1);
        $this->api($this->account())->assertOk();
        $this->assertDatabaseCount('users', 4);
    }
}
