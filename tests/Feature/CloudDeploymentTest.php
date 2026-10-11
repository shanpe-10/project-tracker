<?php

namespace Tests\Feature;

use App\Support\TrackerHolidays;
use App\Support\TrackerMediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudDeploymentTest extends TestCase
{
    private ?string $schema = null;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('PostgreSQL required.');
        if (! str_ends_with(DB::connection()->getDatabaseName(), '_test')) throw new \RuntimeException('Dedicated test database required.');
        $this->schema = 'cloud_deploy_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        DB::statement('SET search_path TO "'.$this->schema.'"');
        // Exercise the entire fresh-install migration path, including database sessions.
        foreach (glob(database_path('migrations/*.php')) as $file) (require $file)->up();
        config(['tracker.cloud'=>true, 'tracker.media_disk'=>'s3']);
        Storage::fake('s3');
        $this->asUser(1);
    }

    protected function tearDown(): void
    {
        foreach (['TRACKER_BOOTSTRAP_NAME','TRACKER_BOOTSTRAP_USERNAME','TRACKER_BOOTSTRAP_PASSWORD'] as $key) putenv($key);
        if ($this->schema !== null) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
        }
        parent::tearDown();
    }

    private function fixtures(): void
    {
        foreach ([1=>'admin',2=>'staff',3=>'staff'] as $id=>$role) {
            DB::table('users')->insert(['id'=>$id,'username'=>'cloud'.$id,'full_name'=>'Cloud '.$id,
                'role'=>$role,'active'=>true,'can_edit'=>$id!==3,'password_hash'=>'fixture']);
        }
    }

    private function asUser(int $id): void
    {
        $this->session = ['tracker_user'=>['id'=>$id], 'tracker_csrf'=>'cloud-token',
            'tracker_created_at'=>time(),'tracker_last_activity'=>time()];
    }

    private function authenticated(): self
    {
        $this->withTrackerSession($this->session);
        app('session')->save();
        return $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId())
            ->withHeader('X-CSRF-Token','cloud-token');
    }

    private function project(): int
    {
        return DB::table('projects')->insertGetId(['data'=>json_encode(['title'=>'Pilot project','approvedBudget'=>'1000',
            'projectNo'=>'1','status'=>'On-going Bidding','updatedAt'=>'2026-10-11T00:00:00.000000Z'])]);
    }

    private function edit(int $id, array $extra = [])
    {
        $data = json_decode(DB::table('projects')->where('id',$id)->value('data'),true);
        return $this->authenticated()->post('/api/project-edit.php', array_merge(['projectId'=>$id,'record'=>json_encode($data),
            'deleteIds'=>'[]','files'=>[UploadedFile::fake()->createWithContent('evidence.txt','Private evidence')]],$extra), ['Accept'=>'application/json']);
    }

    public function test_private_upload_read_delete_and_permissions_use_cloud_storage(): void
    {
        $this->fixtures();
        $id = $this->project();
        $this->getJson('/api/attachments.php?projectId='.$id.'&attachmentId=unknown')->assertUnauthorized();
        $response = $this->edit($id)->assertOk();
        $attachment = $response->json('record.attachments.0');
        $key = 'attachments/'.$id.'/'.$attachment['storedName'];
        Storage::disk('s3')->assertExists($key);
        $this->asUser(3);
        $read = $this->authenticated()->get('/api/attachments.php?projectId='.$id.'&attachmentId='.$attachment['id'].'&download=1')->assertOk();
        $this->assertSame('Private evidence',$read->streamedContent());
        $read->assertHeader('Cache-Control','no-store, private');
        $this->edit($id)->assertForbidden();
        $this->asUser(1);
        $this->edit($id,['files'=>[], 'deleteIds'=>json_encode([$attachment['id']])])->assertOk()->assertJsonCount(0,'record.attachments');
        Storage::disk('s3')->assertMissing($key);
    }

    public function test_failed_project_transaction_removes_new_cloud_upload_and_keeps_record(): void
    {
        $this->fixtures();
        $id = $this->project();
        DB::statement('DROP TABLE audit_log');
        $this->edit($id)->assertStatus(500);
        $this->assertSame([],Storage::disk('s3')->allFiles('attachments'));
        $this->assertDatabaseCount('history',0);
        $this->assertArrayNotHasKey('attachments',json_decode(DB::table('projects')->where('id',$id)->value('data'),true));
    }

    public function test_profile_photo_persists_and_is_private_after_a_second_request(): void
    {
        $this->fixtures();
        $photo = UploadedFile::fake()->createWithContent('photo.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jh5kAAAAASUVORK5CYII='));
        $this->authenticated()->post('/api/profile.php',['fullName'=>'Pilot Admin','photo'=>$photo],['Accept'=>'application/json'])->assertOk();
        $name = DB::table('users')->where('id',1)->value('profile_photo');
        Storage::disk('s3')->assertExists('profile-photos/'.$name);
        $this->authenticated()->get('/api/profile.php?action=photo')->assertOk();
        $this->asUser(2);
        $this->authenticated()->getJson('/api/profile.php?userId=1')->assertForbidden();
    }

    public function test_first_account_bootstrap_never_changes_existing_accounts(): void
    {
        putenv('TRACKER_BOOTSTRAP_NAME=Pilot Owner');
        putenv('TRACKER_BOOTSTRAP_USERNAME=pilot.owner');
        putenv('TRACKER_BOOTSTRAP_PASSWORD=test-only-pilot-password');
        $this->assertSame(0,Artisan::call('tracker:bootstrap-pilot'));
        $user = DB::table('users')->first();
        $this->assertSame('superadmin',$user->role);
        $this->assertTrue(Hash::check('test-only-pilot-password',$user->password_hash));
        putenv('TRACKER_BOOTSTRAP_PASSWORD=replacement-test-password');
        $this->assertSame(0,Artisan::call('tracker:bootstrap-pilot'));
        $this->assertDatabaseCount('users',1);
        $this->assertSame($user->password_hash,DB::table('users')->value('password_hash'));
        $this->assertStringNotContainsString('replacement-test-password',Artisan::output());
    }

    public function test_bootstrap_rejects_missing_credentials_and_skips_any_existing_user(): void
    {
        $this->assertSame(1,Artisan::call('tracker:bootstrap-pilot'));
        $this->assertDatabaseCount('users',0);
        $this->fixtures();
        $this->assertSame(0,Artisan::call('tracker:bootstrap-pilot'));
        $this->assertDatabaseCount('users',3);
    }

    public function test_database_sessions_survive_a_new_handler(): void
    {
        $handler = new \Illuminate\Session\DatabaseSessionHandler(DB::connection(),'sessions',120,app());
        $this->assertTrue($handler->write('pilot-session','saved-session-payload'));
        $second = new \Illuminate\Session\DatabaseSessionHandler(DB::connection(),'sessions',120,app());
        $this->assertSame('saved-session-payload',$second->read('pilot-session'));
        $this->assertTrue($second->destroy('pilot-session'));
    }

    public function test_holiday_settings_persist_in_database_and_detect_stale_updates(): void
    {
        TrackerHolidays::update(0,fn ($state)=>array_replace($state,['enabled'=>false,'overrides'=>['2026-10-12'=>['name'=>'Pilot holiday','nonWorking'=>true]]]));
        $this->assertFalse(TrackerHolidays::state()['enabled']);
        $this->assertSame(1,TrackerHolidays::state()['revision']);
        $this->assertSame('Pilot holiday',TrackerHolidays::state()['overrides']['2026-10-12']['name']);
        $this->expectException(\DomainException::class);
        TrackerHolidays::update(0,fn ($state)=>$state);
    }

    public function test_cloud_backup_contains_private_attachments_and_can_be_downloaded_only_by_admin(): void
    {
        $this->fixtures();
        $id = $this->project();
        $this->edit($id)->assertOk();
        $this->authenticated()->postJson('/api/backups.php',['action'=>'saveSettings','enabled'=>true,'destination'=>'ignored-local-path'])->assertOk()->assertJsonPath('cloud',true);
        $result = $this->authenticated()->postJson('/api/backups.php',['action'=>'runManual'])->assertOk();
        $key = $result->json('latest.key');
        Storage::disk('s3')->assertExists([$key,$key.'.sha256']);
        $archive = Storage::disk('s3')->path($key);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($archive) === true);
        $this->assertStringContainsString('PostgreSQL database dump',$zip->getFromName('database.sql'));
        $this->assertNotFalse($zip->locateName('exports/all-projects.xlsx'));
        $this->assertFalse($zip->locateName('profile-photos'));
        $data = json_decode(DB::table('projects')->where('id',$id)->value('data'),true);
        $this->assertSame('Private evidence',$zip->getFromName('attachments/'.$id.'/'.$data['attachments'][0]['storedName']));
        $zip->close();
        $this->authenticated()->get('/api/backups.php?action=downloadLatest')->assertOk()->assertHeader('Content-Type','application/zip');
        $this->assertNull(app(\App\Http\Controllers\TrackerBackupController::class)->runScheduledBackup());
        $this->asUser(3);
        $this->authenticated()->getJson('/api/backups.php?action=downloadLatest')->assertForbidden();
    }

    public function test_cloud_media_rejects_path_traversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrackerMediaStore::exists('attachments','1/../../secret.txt');
    }

    public function test_cloud_backup_retention_keeps_recent_daily_weekly_and_monthly_snapshots(): void
    {
        $archives = [];
        $start = \Carbon\CarbonImmutable::parse('2026-10-11T12:00:00Z');
        foreach (range(0,120) as $day) $archives[] = ['key'=>'archive-'.$day,'createdAt'=>$start->subDays($day)->toIso8601String()];
        $controller = app(\App\Http\Controllers\TrackerBackupController::class);
        $method = new \ReflectionMethod($controller,'retainedCloudArchives');
        $retained = $method->invoke($controller,$archives);
        $keys = array_column($retained,'key');
        foreach (range(0,6) as $day) $this->assertContains('archive-'.$day,$keys);
        $this->assertLessThanOrEqual(14,count($retained));
        $this->assertNotContains('archive-120',$keys);
        $this->assertCount(3,array_unique(array_map(fn ($a)=>substr($a['createdAt'],0,7),$retained)));
    }
}
