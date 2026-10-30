<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HistoryClearingAndPasswordViewingTest extends TestCase
{
    private ?string $schema = null;
    private int $actor = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (!str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
            $this->schema = 'history_password_'.bin2hex(random_bytes(6));
            DB::statement('CREATE SCHEMA "'.$this->schema.'"');
            DB::statement('SET search_path TO "'.$this->schema.'"');
            DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        } else {
            Schema::create('users', function (Blueprint $t) {
                $t->id(); foreach (['username','full_name','role','password_hash'] as $name) $t->string($name);
                $t->boolean('active')->default(true); $t->timestamp('created_at')->useCurrent();
                foreach (['can_create','can_edit','can_import','can_export','can_delete','can_delete_all','can_resolve_alerts','can_archive'] as $name) $t->boolean($name)->default(false);
            });
            Schema::create('tracker_account_sessions', function (Blueprint $t) { $t->unsignedBigInteger('user_id')->primary(); $t->string('token_hash'); $t->bigInteger('started_at'); $t->bigInteger('last_activity_at'); });
            Schema::create('application_settings', function (Blueprint $t) { $t->string('setting_key')->primary(); $t->json('setting_value'); $t->timestamp('updated_at')->nullable(); });
            Schema::create('audit_log', function (Blueprint $t) { $t->id(); $t->bigInteger('actor_id')->nullable(); foreach (['actor_username','action','entity_type','summary'] as $name) $t->string($name); $t->bigInteger('entity_id')->nullable(); $t->json('metadata')->nullable(); $t->timestamp('created_at'); });
            Schema::create('login_attempts', function (Blueprint $t) { $t->string('attempt_key')->primary(); $t->integer('failed_count'); $t->timestamp('first_failed_at'); $t->timestamp('locked_until')->nullable(); $t->timestamp('updated_at'); });
            Schema::create('project_editing_presence', function (Blueprint $t) { $t->id(); $t->bigInteger('user_id'); $t->string('session_key')->nullable(); $t->timestamp('ended_at')->nullable(); });
            (require database_path('migrations/2026_10_29_000001_create_deletion_requests.php'))->up();
        }
        (require database_path('migrations/2026_10_30_000001_add_history_clearing_and_password_viewing.php'))->up();
        foreach ([1=>'superadmin',2=>'admin',3=>'staff',4=>'staff',5=>'admin'] as $id=>$role) {
            DB::table('users')->insert(['id'=>$id,'username'=>'fixture'.$id,'full_name'=>'Fixture '.$id,'role'=>$role,'active'=>true,'password_hash'=>Hash::make('fixture-super-password')]);
            DB::table('tracker_account_sessions')->insert(['user_id'=>$id,'token_hash'=>hash('sha256','fixture-session-'.$id),'started_at'=>time(),'last_activity_at'=>time()]);
        }
        if ($this->schema) DB::statement("SELECT setval(pg_get_serial_sequence('users','id'), 5, true)");
    }

    protected function tearDown(): void
    {
        if ($this->schema) { DB::statement('SET search_path TO public'); DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE'); }
        parent::tearDown();
    }

    private function authenticated(): self
    {
        $this->withSession(['tracker_user'=>['id'=>$this->actor],'tracker_csrf'=>'fixture-csrf','tracker_session_token'=>'fixture-session-'.$this->actor]);
        app('session')->save();
        return $this->withCredentials()->withCookie(config('session.cookie'),app('session')->getId())->withHeader('X-CSRF-Token','fixture-csrf');
    }

    private function auth(array $body) { return $this->authenticated()->postJson('/api/auth.php',$body); }
    private function clear(string $scope='review', array $extra=[]) { return $this->authenticated()->postJson('/api/deletion-requests.php',$extra+['action'=>'clearHistory','scope'=>$scope,'confirmed'=>true]); }
    private function history(string $scope='review') { return $this->authenticated()->getJson('/api/deletion-requests.php?view=history&scope='.$scope); }
    private function reveal(int $id, string $password='fixture-super-password') { return $this->auth(['action'=>'revealUserPassword','id'=>$id,'currentPassword'=>$password]); }
    private function save(array $extra=[]) { return $this->auth($extra+['action'=>'createUser','username'=>'new.fixture','fullName'=>'New Fixture','role'=>'staff','active'=>true,'password'=>'new-user-password-2026']); }
    private function requestRow(string $status, int $owner=3): int
    {
        return DB::table('deletion_requests')->insertGetId(['target_type'=>'project','status'=>$status,'requested_by'=>$owner,'requester'=>json_encode(['username'=>'fixture'.$owner]),'reason'=>'Fixture request','requested_at'=>now()]);
    }

    public function test_superadmin_reveals_encrypted_password_without_leaking_secrets_in_lists_models_or_audit(): void
    {
        $this->save()->assertOk();
        $row=User::where('username','new.fixture')->firstOrFail();
        $this->assertNotSame('new-user-password-2026',$row->password_encrypted);
        $this->assertSame('new-user-password-2026',Crypt::decryptString($row->password_encrypted));
        $this->assertArrayNotHasKey('password_encrypted',$row->toArray());
        $this->assertTrue(Hash::check('new-user-password-2026',$row->password_hash));
        $this->reveal($row->id)->assertOk()->assertJsonPath('password','new-user-password-2026')->assertHeader('Cache-Control','no-store, private');
        $list=$this->auth(['action'=>'listManagedUsers'])->assertOk()->getContent();
        $this->assertStringNotContainsString('new-user-password-2026',$list);
        $this->assertStringNotContainsString('password_encrypted',$list);
        $audit=DB::table('audit_log')->where('action','account.password_viewed')->first();
        $this->assertSame(1,(int)$audit->actor_id);
        $this->assertStringNotContainsString('new-user-password-2026',json_encode($audit));
    }

    public function test_admin_staff_and_inactive_superadmin_cannot_reveal_passwords(): void
    {
        foreach ([2,3] as $id) { $this->actor=$id; $this->reveal(3)->assertForbidden()->assertJsonMissingPath('password'); }
        $this->actor=1; DB::table('users')->where('id',1)->update(['active'=>false]);
        $this->reveal(3)->assertUnauthorized()->assertJsonMissingPath('password');
    }

    public function test_password_view_requires_csrf_reauthentication_and_limits_incorrect_attempts(): void
    {
        $this->authenticated()->withHeader('X-CSRF-Token','invalid')->postJson('/api/auth.php',['action'=>'revealUserPassword','id'=>3,'currentPassword'=>'fixture-super-password'])->assertStatus(419);
        for ($i=0;$i<5;$i++) $this->reveal(3,'wrong-password')->assertForbidden()->assertJsonMissingPath('password');
        $this->reveal(3)->assertStatus(429);
        $this->assertSame(0,DB::table('audit_log')->where('action','account.password_viewed')->count());
    }

    public function test_legacy_corrupt_and_stale_passwords_require_reset_and_unrelated_edits_preserve_password(): void
    {
        $this->reveal(3)->assertStatus(409);
        $this->save(['action'=>'updateUser','id'=>3,'username'=>'fixture3'])->assertOk();
        $this->reveal(3)->assertOk()->assertJsonPath('password','new-user-password-2026');
        $this->save(['action'=>'updateUser','id'=>3,'username'=>'fixture3','password'=>null])->assertOk();
        $this->reveal(3)->assertOk()->assertJsonPath('password','new-user-password-2026');
        DB::table('users')->where('id',3)->update(['password_encrypted'=>Crypt::encryptString('older-password')]);
        $this->reveal(3)->assertStatus(409);
        DB::table('users')->where('id',3)->update(['password_encrypted'=>'corrupt-ciphertext']);
        $this->reveal(3)->assertStatus(409);
    }

    public function test_clear_history_keeps_active_records_and_other_admins_and_staff_views(): void
    {
        $closed=$this->requestRow('cancelled'); $this->requestRow('rejected'); $this->requestRow('deleted');
        $this->requestRow('pending'); $this->requestRow('approved'); $this->actor=2;
        $this->clear()->assertOk()->assertJsonPath('clearedCount',3);
        $this->history()->assertOk()->assertJsonCount(0,'requests')->assertJsonPath('pendingCount',2);
        $this->authenticated()->getJson('/api/deletion-requests.php?view=active')->assertJsonCount(2,'requests');
        $this->clear()->assertJsonPath('clearedCount',0);
        $this->assertSame(5,DB::table('deletion_requests')->count());
        $this->authenticated()->getJson('/api/deletion-requests.php?scope=review&id='.$closed)->assertOk();
        $this->actor=5; $this->history()->assertJsonCount(3,'requests');
        $this->actor=3; $this->history('mine')->assertJsonCount(4,'requests');
    }

    public function test_staff_clears_only_own_history_and_cannot_clear_review_scope(): void
    {
        $this->requestRow('cancelled',3); $this->requestRow('cancelled',4); $this->requestRow('pending',3); $this->requestRow('approved',3);
        $this->actor=3; $this->clear()->assertForbidden();
        $this->clear('mine')->assertOk()->assertJsonPath('clearedCount',2);
        $this->history('mine')->assertJsonCount(0,'requests')->assertJsonPath('pendingCount',1);
        $this->actor=4; $this->history('mine')->assertJsonCount(1,'requests');
        $this->actor=2; $this->history()->assertJsonCount(2,'requests')->assertJsonPath('pendingCount',2);
        $this->actor=1; $this->clear()->assertForbidden();
    }

    public function test_clearing_covers_all_pages_and_outcomes_but_newly_closed_requests_appear(): void
    {
        for ($i=0;$i<25;$i++) $this->requestRow($i%2 ? 'cancelled':'rejected');
        $pending=$this->requestRow('pending'); $this->actor=2;
        $this->clear('review',['status'=>'rejected','page'=>2])->assertJsonPath('clearedCount',25);
        $this->history()->assertJsonPath('total',0);
        DB::table('deletion_requests')->where('id',$pending)->update(['status'=>'rejected']);
        $this->history()->assertJsonCount(1,'requests')->assertJsonPath('requests.0.id',$pending);
        $this->assertDatabaseHas('audit_log',['action'=>'deletion.history_cleared','actor_id'=>2]);
    }

    public function test_history_clear_requires_confirmation_and_csrf(): void
    {
        $this->requestRow('cancelled'); $this->actor=2;
        $this->clear('review',['confirmed'=>false])->assertStatus(422);
        $this->authenticated()->withHeader('X-CSRF-Token','invalid')->postJson('/api/deletion-requests.php',['action'=>'clearHistory','scope'=>'review','confirmed'=>true])->assertStatus(419);
        $this->history()->assertJsonCount(1,'requests');
    }

    public function test_additive_migration_preserves_existing_accounts_and_requests_and_can_run_twice(): void
    {
        $id=$this->requestRow('cancelled');
        $hash=DB::table('users')->where('id',3)->value('password_hash');
        Schema::drop('deletion_request_history_hidden');
        Schema::table('users',fn (Blueprint $t)=>$t->dropColumn('password_encrypted'));
        $migration=require database_path('migrations/2026_10_30_000001_add_history_clearing_and_password_viewing.php');
        $migration->up(); $migration->up();
        $this->assertSame($hash,DB::table('users')->where('id',3)->value('password_hash'));
        $this->assertSame(5,DB::table('users')->count());
        $this->assertDatabaseHas('deletion_requests',['id'=>$id,'status'=>'cancelled']);
        $this->assertNull(DB::table('users')->where('id',3)->value('password_encrypted'));
        $this->assertTrue(Schema::hasTable('deletion_request_history_hidden'));
    }

    public function test_verified_sign_in_makes_legacy_password_viewable_without_changing_its_hash(): void
    {
        $hash=DB::table('users')->where('id',3)->value('password_hash');
        $response=$this->postJson('/api/auth.php',['action'=>'login','username'=>'fixture3','password'=>'fixture-super-password'])->assertOk();
        $this->assertStringNotContainsString('fixture-super-password',$response->getContent());
        $row=DB::table('users')->where('id',3)->first();
        $this->assertSame($hash,$row->password_hash);
        $this->assertSame('fixture-super-password',Crypt::decryptString($row->password_encrypted));
        $this->actor=1;$this->reveal(3)->assertOk()->assertJsonPath('password','fixture-super-password');
    }

    public function test_failed_and_inactive_sign_ins_do_not_save_passwords(): void
    {
        $this->postJson('/api/auth.php',['action'=>'login','username'=>'fixture3','password'=>'incorrect-password'])->assertUnauthorized();
        $this->assertNull(DB::table('users')->where('id',3)->value('password_encrypted'));
        DB::table('users')->where('id',3)->update(['active'=>false]);
        $this->postJson('/api/auth.php',['action'=>'login','username'=>'fixture3','password'=>'fixture-super-password'])->assertForbidden();
        $this->assertNull(DB::table('users')->where('id',3)->value('password_encrypted'));
    }

    public function test_account_directory_reports_availability_without_exposing_password_values(): void
    {
        $this->save(['action'=>'updateUser','id'=>3,'username'=>'fixture3'])->assertOk();
        $users=$this->auth(['action'=>'listManagedUsers'])->assertOk()->json('users');
        $byId=collect($users)->keyBy('id');
        $this->assertTrue($byId[3]['passwordAvailable']);$this->assertFalse($byId[4]['passwordAvailable']);
        $this->assertArrayNotHasKey('password_encrypted',$byId[3]);
        $this->reveal(4)->assertStatus(409)->assertJsonPath('passwordAvailable',false);
    }

    public function test_all_passwords_requires_superadmin_reauthentication_and_includes_every_account(): void
    {
        $this->save(['action'=>'updateUser','id'=>3,'username'=>'fixture3'])->assertOk();
        $this->save(['action'=>'updateUser','id'=>4,'username'=>'fixture4','active'=>false,'password'=>'second-user-password-2026'])->assertOk();
        $this->auth(['action'=>'revealAllUserPasswords'])->assertStatus(422);
        $this->auth(['action'=>'revealAllUserPasswords','currentPassword'=>'incorrect-password'])->assertForbidden()->assertJsonMissingPath('accounts');
        $response=$this->auth(['action'=>'revealAllUserPasswords','currentPassword'=>'fixture-super-password'])
            ->assertOk()->assertJsonCount(5,'accounts')->assertJsonPath('total',5)->assertJsonPath('availableCount',3)
            ->assertHeader('Cache-Control','no-store, private')->assertHeader('Pragma','no-cache');
        $accounts=collect($response->json('accounts'))->keyBy('id');
        $this->assertSame('fixture-super-password',$accounts[1]['password']);
        $this->assertSame('new-user-password-2026',$accounts[3]['password']);
        $this->assertSame('second-user-password-2026',$accounts[4]['password']);
        $this->assertFalse($accounts[4]['active']);
        $this->assertFalse($accounts[2]['passwordAvailable']);$this->assertNull($accounts[2]['password']);
        $this->assertArrayNotHasKey('password_hash',$accounts[1]);$this->assertArrayNotHasKey('password_encrypted',$accounts[3]);
        $audit=DB::table('audit_log')->where('action','account.passwords_viewed')->first();
        $this->assertSame(1,(int)$audit->actor_id);
        foreach(['fixture-super-password','new-user-password-2026','second-user-password-2026'] as $secret)$this->assertStringNotContainsString($secret,json_encode($audit));
    }

    public function test_admin_staff_and_missing_csrf_cannot_read_all_passwords(): void
    {
        foreach([2,3] as $id){$this->actor=$id;$this->auth(['action'=>'revealAllUserPasswords','currentPassword'=>'fixture-super-password'])->assertForbidden()->assertJsonMissingPath('accounts');}
        $this->actor=1;
        $this->authenticated()->withHeader('X-CSRF-Token','invalid')->postJson('/api/auth.php',['action'=>'revealAllUserPasswords','currentPassword'=>'fixture-super-password'])->assertStatus(419)->assertJsonMissingPath('accounts');
        DB::table('users')->where('id',1)->update(['active'=>false]);
        $this->auth(['action'=>'revealAllUserPasswords','currentPassword'=>'fixture-super-password'])->assertUnauthorized()->assertJsonMissingPath('accounts');
    }

    public function test_all_passwords_uses_the_same_incorrect_password_limit_as_individual_viewing(): void
    {
        for($i=0;$i<5;$i++)$this->reveal(3,'incorrect-password')->assertForbidden();
        $this->auth(['action'=>'revealAllUserPasswords','currentPassword'=>'fixture-super-password'])->assertStatus(429)->assertJsonMissingPath('accounts');
        $this->assertSame(0,DB::table('audit_log')->where('action','account.passwords_viewed')->count());
    }

    public function test_all_passwords_marks_stale_and_corrupt_values_unavailable(): void
    {
        DB::table('users')->where('id',3)->update(['password_encrypted'=>Crypt::encryptString('old-password-value')]);
        DB::table('users')->where('id',4)->update(['password_encrypted'=>'invalid-ciphertext']);
        $response=$this->auth(['action'=>'revealAllUserPasswords','currentPassword'=>'fixture-super-password'])->assertOk()->assertJsonPath('availableCount',1);
        $accounts=collect($response->json('accounts'))->keyBy('id');
        $this->assertFalse($accounts[3]['passwordAvailable']);$this->assertNull($accounts[3]['password']);
        $this->assertFalse($accounts[4]['passwordAvailable']);$this->assertNull($accounts[4]['password']);
        $this->assertStringNotContainsString('old-password-value',$response->getContent());
    }
}
