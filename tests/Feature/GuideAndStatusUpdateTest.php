<?php

namespace Tests\Feature;

use App\Http\Controllers\TrackerDataController;
use App\Support\TrackerProjectInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GuideAndStatusUpdateTest extends TestCase
{
    private ?string $schema = null;
    private array $session;
    private ?string $photoDirectory = null;
    private array $createdArchives = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL integration test.');
        if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'guide_status_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
        foreach ([1 => 'staff', 2 => 'staff', 3 => 'superadmin'] as $id => $role) {
            DB::table('users')->insert(['id' => $id, 'username' => 'fixture'.$id, 'full_name' => 'Fixture '.$id,
                'role' => $role, 'active' => true, 'can_create' => true, 'can_edit' => true, 'password_hash' => 'fixture']);
        }
        $this->asUser(1);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdArchives as $path) @unlink($path);
        if ($this->photoDirectory) {
            @unlink($this->photoDirectory.'/fixture.png');
            @rmdir($this->photoDirectory);
        }
        if ($this->schema) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    private function asUser(int $id): void
    {
        $this->session = ['tracker_user' => ['id' => $id], 'tracker_csrf' => 'guide-token',
            'tracker_created_at' => time(), 'tracker_last_activity' => time()];
    }

    private function postApi(string $endpoint, array $body)
    {
        return $this->withSession($this->session)->withHeader('X-CSRF-Token', 'guide-token')->postJson('/api/'.$endpoint, $body);
    }

    public function test_final_inspection_sets_pending_without_prior_milestones_or_an_elapsed_contract(): void
    {
        $record = ['biddingDate' => '2026-10-01', 'contractRef' => '2026-10-05', 'contractDuration' => 100, 'finalInspection' => '2026-10-09'];
        foreach (['2026-10-08', '2027-10-08'] as $date) {
            $this->assertSame('Pending', TrackerProjectInput::automaticStatus($record, new \DateTimeImmutable($date)));
        }
        $this->assertSame('Pending', TrackerProjectInput::automaticStatus(['finalInspection' => '2026-10-09']));
        $this->assertSame('Pre-inspection', TrackerProjectInput::automaticStatus(['biddingDate' => '2026-10-01', 'finalInspection' => '2026-02-30']));
        $this->assertSame('Final-inspection', TrackerProjectInput::automaticStatus(['contractEndDate' => '2026-10-08'], new \DateTimeImmutable('2026-10-09')));
    }

    public function test_completion_and_release_keep_priority_above_final_inspection(): void
    {
        $record = ['finalInspection' => '2026-10-08'];
        $this->assertSame('Completed', TrackerProjectInput::automaticStatus($record + ['completion' => '2026-10-09']));
        $this->assertSame('Released', TrackerProjectInput::automaticStatus($record + ['swa' => '2026-10-09', 'completion' => '2026-10-09']));
        $this->assertSame('Completed', TrackerProjectInput::automaticStatus($record + ['status' => 'Completed']));
        $this->assertSame('Released', TrackerProjectInput::automaticStatus($record + ['status' => 'Released']));
    }

    public function test_regular_save_and_attachment_save_persist_the_new_rule_and_filter_correctly(): void
    {
        $data = ['title' => 'Inspection before contract due', 'approvedBudget' => '1000', 'biddingDate' => '2026-10-01',
            'contractRef' => '2026-10-05', 'contractDuration' => 100, 'finalInspection' => '2026-10-09', 'status' => 'Pre-inspection'];
        $record = $this->postApi('data.php', ['action' => 'saveProject', 'record' => $data])->assertOk()->assertJsonPath('record.status', 'Pending')->json('record');
        $this->assertSame('Pending', DB::table('projects')->where('id', $record['id'])->value('project_status'));
        $this->withSession($this->session)->getJson('/api/data.php?action=listProjects&status=Pending')->assertOk()->assertJsonPath('totalRecords', 1);
        $record['finalInspection'] = '';
        $record = $this->postApi('project-edit.php', ['projectId' => $record['id'], 'record' => json_encode($record), 'deleteIds' => '[]'])->assertOk()->assertJsonPath('record.status', 'Pre-inspection')->json('record');
        $record['finalInspection'] = '2026-10-09';
        $this->postApi('project-edit.php', ['projectId' => $record['id'], 'record' => json_encode($record), 'deleteIds' => '[]'])->assertOk()->assertJsonPath('record.status', 'Pending');
    }

    public function test_chat_api_is_removed_for_all_roles(): void
    {
        foreach ([1, 3] as $id) {
            $this->asUser($id);
            $this->withSession($this->session)->getJson('/api/chat.php')->assertNotFound();
            $this->postApi('chat.php', ['action' => 'send', 'body' => 'Removed feature'])->assertNotFound();
        }
        $this->assertFileDoesNotExist(public_path('js/chat.js'));
        $this->assertFileDoesNotExist(public_path('css/chat.css'));
        $this->assertFalse(class_exists(\App\Http\Controllers\TrackerChatController::class));
    }

    public function test_profile_photos_work_without_chat_tables_and_keep_access_limits(): void
    {
        $this->assertFalse(Schema::hasTable('tracker_chat_members'));
        $this->photoDirectory = sys_get_temp_dir().'/tracker-profile-'.bin2hex(random_bytes(6));
        mkdir($this->photoDirectory);
        file_put_contents($this->photoDirectory.'/fixture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aPz8AAAAASUVORK5CYII='));
        config(['tracker.profile_photos' => $this->photoDirectory]);
        DB::table('users')->whereIn('id', [1, 2])->update(['profile_photo' => 'fixture.png']);
        $this->withSession($this->session)->get('/api/profile.php')->assertOk();
        $this->withSession($this->session)->getJson('/api/profile.php?userId=2')->assertForbidden();
        $this->asUser(3);
        $this->withSession($this->session)->get('/api/profile.php?userId=2')->assertOk();
    }

    public function test_retirement_archives_messages_before_removing_the_chat_tables(): void
    {
        foreach (['2026_10_07_000001_create_tracker_chat.php', '2026_10_07_000002_enhance_tracker_chat.php', '2026_10_07_000003_chat_history_clear.php', '2026_10_07_000004_single_message_delete.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        DB::table('tracker_conversations')->insert(['id' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tracker_messages')->insert(['id' => 1, 'conversation_id' => 1, 'user_id' => 1, 'body' => 'Historical fixture message', 'created_at' => now()]);
        $directory = storage_path('app/private/retired-chat');
        $before = glob($directory.'/*.json') ?: [];
        $migration = require database_path('migrations/2026_10_10_000002_retire_tracker_chat.php');
        $migration->up();
        $this->createdArchives = array_values(array_diff(glob($directory.'/*.json') ?: [], $before));
        $this->assertCount(1, $this->createdArchives);
        $archive = json_decode(file_get_contents($this->createdArchives[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Historical fixture message', $archive['tables']['tracker_messages']['rows'][0]['body']);
        $this->assertSame(1, $archive['tables']['tracker_conversations']['rows'][0]['id']);
        foreach (array_keys($archive['tables']) as $table) $this->assertFalse(Schema::hasTable($table));
        $this->assertTrue(Schema::hasTable('projects'));
        $this->assertTrue(Schema::hasTable('project_editing_presence'));
        $migration->up(); // Fresh installs and repeat checks are harmless.
    }
}
