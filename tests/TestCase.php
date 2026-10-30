<?php
namespace Tests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
abstract class TestCase extends BaseTestCase
{
    private ?string $fixtureSchema = null;
    private ?string $calendarFixture = null;
    public function createApplication() {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        // Laravel's cache path detector needs drive prefixes for Windows temp paths.
        if (PHP_OS_FAMILY === 'Windows') foreach (range('A','Z') as $drive) $app->addAbsoluteCachePathPrefix($drive.':');
        if (getenv('TRACKER_TEST_STORAGE_PATH')) $app->useStoragePath(getenv('TRACKER_TEST_STORAGE_PATH'));
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        if (isset(\Illuminate\Foundation\Testing\CachedState::$cachedConfig) && isset($this->traitsUsedByTest[\Illuminate\Foundation\Testing\WithCachedConfig::class])) $this->markConfigCached($app);
        if (isset(\Illuminate\Foundation\Testing\CachedState::$cachedRoutes) && isset($this->traitsUsedByTest[\Illuminate\Foundation\Testing\WithCachedRoutes::class])) $app->booting(fn ()=>$this->markRoutesCached($app));
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
    protected function setUp(): void {
        parent::setUp();
        if (getenv('TRACKER_TEST_STORAGE_PATH')) $this->app->useStoragePath(getenv('TRACKER_TEST_STORAGE_PATH'));
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (!str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
            $this->fixtureSchema = 'release_test_'.bin2hex(random_bytes(6));
            DB::statement('CREATE SCHEMA "'.$this->fixtureSchema.'"');
            DB::statement('SET search_path TO "'.$this->fixtureSchema.'"');
        }
    }
    protected function tearDown(): void {
        if ($this->fixtureSchema) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA "'.$this->fixtureSchema.'" CASCADE');
        }
        if ($this->calendarFixture) { @unlink($this->calendarFixture); @unlink($this->calendarFixture.'.lock'); }
        parent::tearDown();
    }
    /** Upgrade pre-token test session fixtures; production authentication is never bypassed. */
    public function withSession(array $data) {
        if (isset($data['tracker_created_at'], $data['tracker_user']['id']) && !isset($data['tracker_session_token'])) {
            if (!Schema::hasTable('tracker_account_sessions')) {
                (require database_path('migrations/2026_10_11_000002_create_tracker_account_sessions.php'))->up();
            }
            $id=(int)$data['tracker_user']['id']; $token='legacy-fixture-session-'.$id;
            if (DB::table('users')->where('id',$id)->exists() && !DB::table('tracker_account_sessions')->where('user_id',$id)->exists()) {
                DB::table('tracker_account_sessions')->insert(['user_id'=>$id,'token_hash'=>hash('sha256',$token),'started_at'=>$data['tracker_created_at'],'last_activity_at'=>$data['tracker_last_activity'] ?? $data['tracker_created_at']]);
            }
            $data['tracker_session_token']=$token;
        }
        parent::withSession($data);
        app('session')->save();
        return $this->withCredentials()->withCookie(config('session.cookie'),app('session')->getId());
    }
    /** A completed-import fixture now uploads and finalizes; staging has separate regressions. */
    protected function finishImportFixture($response, array $body, string $endpoint='data.php') {
        if ($endpoint === 'data.php' && ($body['action'] ?? '') === 'bulkSaveProjects' && $response->status() === 200) {
            return $this->postJson('/api/data.php',['action'=>'completeImport','batchId'=>$body['batchId']]);
        }
        return $response;
    }

    protected function useWeekdayCalendarFixture(): void {
        $this->calendarFixture = storage_path('framework/testing-calendar-'.bin2hex(random_bytes(6)).'.json');
        config(['tracker_holidays.state_path'=>$this->calendarFixture]);
        \App\Support\TrackerHolidays::update(0, function ($state) { $state['nonWorkingDays']=[0,6]; $state['excludeHolidays']=false; return $state; });
    }
    protected function confirmDirectFixture(array $target) {
        $preview=$this->postJson('/api/deletion-requests.php',['action'=>'previewDirect']+$target)->assertOk()->json('selection');
        $prepared=$this->postJson('/api/deletion-requests.php',['action'=>'prepareDirect','expectedVersion'=>$preview['version'],'reason'=>'Disposable regression fixture']+$target)->assertOk();
        return $this->postJson('/api/deletion-requests.php',['action'=>'confirmDirect','confirmationToken'=>$prepared->json('confirmationToken'),'confirmed'=>true]);
    }

}
