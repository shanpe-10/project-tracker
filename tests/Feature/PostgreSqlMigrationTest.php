<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
class PostgreSqlMigrationTest extends ComprehensiveUatTest
{
    protected function setUp(): void {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') $this->markTestSkipped('Run with phpunit.pgsql.xml against PostgreSQL.');
    }
    public function test_constraints_dates_json_identity_and_foreign_keys(): void {
        $this->assertNull(DB::selectOne("SELECT tracker_date('2026-02-30') AS value")->value);
        $this->assertNull(DB::selectOne("SELECT tracker_date('garbage') AS value")->value);
        $id=DB::table('projects')->insertGetId(['data'=>json_encode(['remarks'=>'Unicode 台灣','prDate'=>'invalid']),'project_no'=>'PG-1','updated_by'=>1]);
        $this->assertGreaterThan(0,$id);
        $this->assertSame('Unicode 台灣',json_decode(DB::table('projects')->where('id',$id)->value('data'),true)['remarks']);
        DB::table('projects')->where('id',$id)->update(['title'=>'Changed']);
        $this->assertSame('Changed',DB::table('projects')->where('id',$id)->value('title'));
        DB::table('users')->where('id',1)->delete();
        $this->assertNull(DB::table('projects')->where('id',$id)->value('updated_by'));
        DB::table('projects')->where('id',$id)->delete();
        $this->assertSame(0,DB::table('projects')->count());
        foreach (["INSERT INTO users(username,full_name,password_hash,role) VALUES ('bad','Bad','hash','invalid')", "INSERT INTO projects(data,updated_by) VALUES ('{}',999999)", "INSERT INTO projects(data) VALUES ('invalid')"] as $sql) {
            DB::beginTransaction();
            try { DB::statement($sql); $this->fail('Expected constraint violation'); }
            catch (\Illuminate\Database\QueryException $error) { $this->assertContains($error->getCode(),['23514','23503','22P02']); }
            finally { DB::rollBack(); }
        }
    }
    public function test_login_permissions_case_insensitive_username_and_search(): void {
        $response=$this->postJson('/api/auth.php',['action'=>'login','username'=>'ADMIN','password'=>'test-password'])->assertOk();
        $token=$response->json('csrfToken');
        $this->withHeader('X-CSRF-Token',$token)->postJson('/api/auth.php',['action'=>'listManagedUsers'])->assertForbidden();
        DB::table('projects')->insert(['data'=>'{}','project_no'=>'ABC-1','title'=>'Mixed CASE']);
        $this->getJson('/api/data.php?action=listProjects&search=mixed')->assertOk()->assertJsonPath('totalRecords',1);
        $this->getJson('/api/data.php?action=listProjects&search=abc')->assertOk()->assertJsonPath('totalRecords',1);
        DB::beginTransaction();
        try { DB::table('users')->insert(['username'=>'AdMiN','full_name'=>'Duplicate','password_hash'=>'hash']); $this->fail('Expected duplicate'); }
        catch (\Illuminate\Database\QueryException $error) { $this->assertSame('23505',$error->getCode()); }
        finally { DB::rollBack(); }
    }
    public function test_postgresql_backup_contains_restorable_dump(): void {
        $destination = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tracker-pg-backup-'.bin2hex(random_bytes(5));
        mkdir($destination.'/source-attachments/1',0750,true);
        mkdir($destination.'/source-photos',0750,true);
        file_put_contents($destination.'/source-attachments/1/evidence.txt','Backup attachment fixture');
        file_put_contents($destination.'/source-photos/avatar.png','Backup photo fixture');
        config(['tracker.attachments'=>$destination.'/source-attachments','tracker.profile_photos'=>$destination.'/source-photos']);
        $controller = new \App\Http\Controllers\TrackerBackupController;
        $method = new \ReflectionMethod($controller, 'createBackup');
        $archive = $method->invoke($controller, $destination);
        try {
            $this->assertFileExists($archive.'.sha256');
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($archive) === true);
            $dump = $zip->getFromName('database.sql');
            $this->assertStringContainsString('PostgreSQL database dump', $dump);
            $this->assertNotFalse($zip->locateName('exports/all-projects.xlsx'));
            $this->assertSame('Backup attachment fixture',$zip->getFromName('attachments/1/evidence.txt'));
            $this->assertFalse($zip->locateName('profile-photos/avatar.png'));
            $this->assertStringNotContainsString('CREATE TABLE',$dump);
            $this->assertFalse($zip->locateName('application/artisan'));
            for ($i=0;$i<$zip->numFiles;$i++) {
                $name=$zip->getNameIndex($i);
                $this->assertDoesNotMatchRegularExpression('#backup-work-|pgpass|bootstrap/cache|storage/framework|storage/logs#',$name);
            }
            $zip->close();
            $this->assertStringStartsWith(hash_file('sha256', $archive), file_get_contents($archive.'.sha256'));
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($destination);
        }
    }

    public function test_manual_and_automatic_backups_contain_only_project_data(): void {
        DB::table('users')->where('id',1)->update(['role'=>'admin']);
        $destination = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tracker-project-backup-'.bin2hex(random_bytes(5));
        mkdir($destination.'/storage/app',0750,true);
        mkdir($destination.'/source-attachments/1',0750,true);
        mkdir($destination.'/source-photos',0750,true);
        file_put_contents($destination.'/source-attachments/1/evidence.txt','Project attachment');
        file_put_contents($destination.'/source-photos/avatar.png','System photo');
        config(['tracker.attachments'=>$destination.'/source-attachments','tracker.profile_photos'=>$destination.'/source-photos']);
        $storage = storage_path();
        $this->app->useStoragePath($destination.'/storage');
        $settings = $destination.'/storage/app/backup-settings.json';
        file_put_contents($settings,json_encode(['enabled'=>true,'destination'=>$destination]));
        $id = DB::table('projects')->insertGetId(['data'=>json_encode(['title'=>'Backup project','projectNo'=>'1']),'project_no'=>'1','updated_by'=>1]);
        DB::table('history')->insert(['data'=>json_encode(['projectId'=>$id,'action'=>'create']),'project_id'=>$id,'updated_by'=>1]);
        $controller = new \App\Http\Controllers\TrackerBackupController;
        try {
            $archive = $controller->runScheduledBackup();
            $response = $this->withSession(['tracker_user'=>['id'=>1,'role'=>'admin'],'tracker_csrf'=>'backup-token','tracker_created_at'=>time(),'tracker_last_activity'=>time()])->withHeader('X-CSRF-Token','backup-token')->postJson('/api/backups.php',['action'=>'runManual'])->assertOk()->assertJsonPath('ok',true);
            $manual = (string) $response->json('archivePath');
            $this->assertNotNull($archive,'An enabled schedule must produce a project backup.');
            $this->assertStringStartsWith('project-data-',basename($archive));
            $this->assertStringStartsWith('project-data-',basename($manual));
            $manualZip = new \ZipArchive;
            $this->assertTrue($manualZip->open($manual) === true);
            $this->assertFalse($manualZip->locateName('application/artisan'));
            $this->assertFalse($manualZip->locateName('profile-photos/avatar.png'));
            $this->assertStringNotContainsString('CREATE TABLE',$manualZip->getFromName('database.sql'));
            $manualZip->close();
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($archive)===true);
            $dump = $zip->getFromName('database.sql');
            foreach (['projects','history','project_number_sequences','deletion_requests','deletion_request_targets','deletion_request_history_hidden','audit_log','import_jobs','import_job_changes','import_staging_chunks'] as $table) $this->assertMatchesRegularExpression('/COPY [^\r\n]+\.'.preg_quote($table,'/').' /',$dump);
            $this->assertStringContainsString('Backup project',$dump);
            $this->assertStringContainsString('setval(',$dump);
            $this->assertDoesNotMatchRegularExpression('/COPY [^\r\n]+\.(users|application_settings|login_attempts) /',$dump);
            $this->assertStringNotContainsString('CREATE TABLE',$dump);
            $this->assertNotFalse($zip->locateName('exports/all-projects.xlsx'));
            $this->assertSame('Project attachment',$zip->getFromName('attachments/1/evidence.txt'));
            $this->assertStringContainsString('data-only dump',$zip->getFromName('README.txt'));
            for($i=0;$i<$zip->numFiles;$i++) $this->assertDoesNotMatchRegularExpression('#^(application/|profile-photos/)|pgpass|\.env#',$zip->getNameIndex($i));
            $zip->close(); unset($zip);
            $this->assertStringContainsString(hash_file('sha256',$archive),file_get_contents($archive.'.sha256'));
            foreach(['weekly','monthly'] as $period) {
                $this->assertCount(1,glob($destination.'/'.$period.'/project-data-*.zip'));
                $this->assertCount(0,glob($destination.'/'.$period.'/project-tracker-*.zip'));
            }
            $this->assertNull($controller->runScheduledBackup());
            file_put_contents($settings,json_encode(['enabled'=>false,'destination'=>$destination]));
            $this->assertNull($controller->runScheduledBackup());
            $this->assertSame(1,DB::table('audit_log')->where('action','backup.automatic_created')->count());
        } finally {
            $this->app->useStoragePath($storage);
            if(isset($zip)) $zip->close();
            if(str_starts_with(realpath($destination),realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'tracker-project-backup-')) \Illuminate\Support\Facades\File::deleteDirectory($destination);
        }
    }
}
