<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrackerPreviewTest extends TestCase
{
    private array $admin = [
        'id' => 5, 'username' => 'admin', 'fullName' => 'Administrator', 'role' => 'admin',
        'canCreate' => true, 'canEdit' => true, 'canImport' => true, 'canExport' => true,
        'canDelete' => true, 'canDeleteAll' => true, 'canResolveAlerts' => true, 'canArchive' => true,
    ];

    public function test_login_page_and_anonymous_session_load(): void
    {
        $this->get('/')->assertOk()->assertSee('Project Tracker');
        $this->postJson('/api/auth.php', ['action' => 'session'])
            ->assertOk()->assertJson(['ok' => true, 'authenticated' => false]);
    }

    public function test_data_api_rejects_anonymous_requests(): void
    {
        $this->getJson('/api/data.php')->assertUnauthorized();
    }

    public function test_authenticated_preview_reads_existing_database(): void
    {
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('Run this integration check with the PostgreSQL test configuration.');
        }
        $session = ['tracker_user' => $this->admin, 'tracker_csrf' => 'test-token', 'tracker_created_at' => time(), 'tracker_last_activity' => time()];
        $total = DB::table('projects')->count();
        $active = DB::table('projects')->whereNull('archived_at')->count();
        $this->withSession($session)->getJson('/api/data.php')->assertOk()->assertJsonPath('ok', true)->assertJsonCount($total, 'projects');
        $this->withSession($session)->getJson('/api/data.php?action=listProjects&lifecycle=all&pageSize=100')->assertOk()->assertJsonPath('totalRecords', $active);
        $this->withSession($session)->getJson('/api/data.php?action=dashboard')->assertOk()->assertJsonPath('metrics.total', $active);
    }

    public function test_csv_export_and_import_rollback_on_postgresql(): void
    {
        if (config('database.default') === 'sqlite') $this->markTestSkipped('Run this integration check with the PostgreSQL test configuration.');
        $session = ['tracker_user' => $this->admin, 'tracker_csrf' => 'test-token', 'tracker_created_at' => time(), 'tracker_last_activity' => time()];
        $this->withSession($session)->get('/api/data.php?action=listProjects&format=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
        $batch = 'codex_test_'.bin2hex(random_bytes(8));
        $before = DB::table('projects')->count();
        $this->withSession($session)->withHeader('X-CSRF-Token', 'test-token')->postJson('/api/data.php', ['action' => 'bulkSaveProjects', 'batchId' => $batch, 'records' => [['title' => 'Rollback Integration Test', 'status' => 'Pending', 'biddingDate' => '2026-09-17']]])->assertOk()->assertJsonPath('count', 1);
        $this->assertSame($before + 1, DB::table('projects')->count());
        $this->withSession($session)->withHeader('X-CSRF-Token', 'test-token')->postJson('/api/data.php', ['action' => 'rollbackImport', 'batchId' => $batch])->assertOk()->assertJsonPath('count', 1);
        $this->assertSame($before, DB::table('projects')->count());
    }

    public function test_manual_backup_creates_a_verified_archive_on_postgresql(): void
    {
        if (config('database.default') === 'sqlite') $this->markTestSkipped('Run this integration check with the PostgreSQL test configuration.');
        $session = ['tracker_user' => $this->admin, 'tracker_csrf' => 'test-token', 'tracker_created_at' => time(), 'tracker_last_activity' => time()];
        $response = $this->withSession($session)->withHeader('X-CSRF-Token', 'test-token')->postJson('/api/backups.php', ['action' => 'runManual'])->assertOk()->assertJsonPath('ok', true);
        $archive = (string) $response->json('archivePath');
        $this->assertFileExists($archive); $this->assertFileExists($archive.'.sha256');
        $zip = new \ZipArchive; $this->assertTrue($zip->open($archive) === true); $this->assertNotFalse($zip->locateName('database.sql')); $zip->close();
        @unlink($archive); @unlink($archive.'.sha256');
    }
}
