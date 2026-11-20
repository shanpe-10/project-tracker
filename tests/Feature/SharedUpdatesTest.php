<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SharedUpdatesTest extends TestCase
{
    private ?string $schema = null;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL integration test.');
        if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'shared_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        foreach ([1=>'staff', 2=>'admin', 4=>'staff', 10=>'admin', 5=>'admin', 12=>'admin'] as $id=>$role) {
            DB::table('users')->insert(['id'=>$id, 'username'=>'account'.$id, 'full_name'=>'Account '.$id,
                'role'=>$role, 'active'=>true, 'can_edit'=>true, 'password_hash'=>'fixture',
                'created_at'=>$id === 10 ? '2020-01-01' : '2021-01-01']);
        }
        $this->asUser(12);
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
        $this->session = ['tracker_user'=>['id'=>$id], 'tracker_csrf'=>'shared-token',
            'tracker_created_at'=>time(), 'tracker_last_activity'=>time()];
    }

    private function api(string $endpoint, array $body)
    {
        return $this->withSession($this->session)->withHeader('X-CSRF-Token', 'shared-token')->postJson('/api/'.$endpoint, $body);
    }

    private function project(): array
    {
        $data = ['title'=>'Shared project', 'approvedBudget'=>'1000', 'projectNo'=>'1',
            'status'=>'Pending', 'updatedAt'=>'2026-10-01T00:00:00.000000Z'];
        $id = DB::table('projects')->insertGetId(['data'=>json_encode($data)] + TrackerDataController::projectColumns($data));
        return $data + ['id'=>$id];
    }

    public function test_original_account_is_identified_by_creation_and_rejects_both_deletion_paths(): void
    {
        $users = $this->api('auth.php', ['action'=>'listManagedUsers'])->assertOk()->json('users');
        $protected = array_values(array_filter($users, fn ($user) => $user['isOriginalAdmin']));
        $this->assertCount(1, $protected);
        $this->assertSame(10, $protected[0]['id']);
        foreach (['deleteUser', 'rejectUser'] as $action) {
            $this->api('auth.php', ['action'=>$action, 'id'=>10])->assertStatus(422)->assertJsonPath('ok', false);
        }
        $this->assertTrue(DB::table('users')->where('id', 10)->exists());
        $this->api('auth.php', ['action'=>'deleteUser', 'id'=>5])->assertOk();
        $this->assertFalse(DB::table('users')->where('id', 5)->exists());
    }

    public function test_protection_survives_renaming_disabling_and_role_changes(): void
    {
        $this->api('auth.php', ['action'=>'updateUser', 'id'=>10, 'username'=>'renamed',
            'fullName'=>'Renamed original', 'role'=>'staff', 'active'=>false])->assertOk();
        foreach (['deleteUser', 'rejectUser'] as $action) {
            $this->api('auth.php', ['action'=>$action, 'id'=>10])->assertStatus(422);
        }
        $users = $this->api('auth.php', ['action'=>'listManagedUsers'])->assertOk()->json('users');
        $original = array_values(array_filter($users, fn ($user) => $user['id'] === 10))[0];
        $this->assertTrue($original['isOriginalAdmin']);
        $this->assertSame('renamed', $original['username']);
        $this->assertSame(10, json_decode(DB::table('application_settings')->where('setting_key', 'original_admin_id')->value('setting_value'), true));
    }

    public function test_self_and_last_active_admin_guards_are_preserved(): void
    {
        $this->api('auth.php', ['action'=>'deleteUser', 'id'=>12])->assertStatus(422);
        DB::table('users')->whereIn('id', [2, 5, 10])->update(['active'=>false]);
        $this->api('auth.php', ['action'=>'updateUser', 'id'=>12, 'username'=>'account12',
            'fullName'=>'Account 12', 'role'=>'staff', 'active'=>true])->assertStatus(422);
    }

    public function test_bootstrap_and_version_are_uncached_and_detect_edits_and_timeline_changes(): void
    {
        $record = $this->project();
        $this->asUser(2);
        $bootstrap = $this->withSession($this->session)->getJson('/api/data.php')->assertOk();
        $this->assertStringContainsString('no-store', $bootstrap->headers->get('Cache-Control'));
        $initial = $bootstrap->json('version');
        $this->assertNotEmpty($initial);
        $version = $this->withSession($this->session)->getJson('/api/data.php?action=projectVersion')->assertOk();
        $this->assertSame($initial, $version->json('version'));
        $this->assertStringContainsString('no-store', $version->headers->get('Cache-Control'));
        $this->api('data.php', ['action'=>'saveProject', 'record'=>array_replace($record, ['title'=>'Saved elsewhere'])])->assertOk();
        $updated = $this->withSession($this->session)->getJson('/api/data.php')->assertOk();
        $this->assertNotSame($initial, $updated->json('version'));
        $this->assertSame('Saved elsewhere', $updated->json('projects.0.title'));
        $beforeSettings = $updated->json('version');
        $this->api('data.php', ['action'=>'saveTimelineSettings', 'settings'=>[
            'noa'=>11, 'ntp'=>30, 'material'=>15, 'completion'=>15, 'finalInspection'=>7, 'bidding'=>15,
        ]])->assertOk();
        $this->assertNotSame($beforeSettings, $this->withSession($this->session)->getJson('/api/data.php?action=projectVersion')->json('version'));
    }

    public function test_two_accounts_cannot_overwrite_each_others_normal_edits(): void
    {
        $original = $this->project();
        $this->asUser(2);
        $this->api('project-edit.php', ['projectId'=>$original['id'],
            'record'=>json_encode(array_replace($original, ['title'=>'First editor saved'])), 'deleteIds'=>'[]'])->assertOk();
        $this->asUser(4);
        $this->api('project-edit.php', ['projectId'=>$original['id'],
            'record'=>json_encode(array_replace($original, ['title'=>'Stale editor draft'])), 'deleteIds'=>'[]'])->assertStatus(409);
        $this->api('data.php', ['action'=>'saveProject',
            'record'=>array_replace($original, ['title'=>'Stale alternate API'])])->assertStatus(409);
        $saved = json_decode(DB::table('projects')->where('id', $original['id'])->value('data'), true);
        $this->assertSame('First editor saved', $saved['title']);
        $this->assertSame(1, DB::table('history')->count());
    }
}
