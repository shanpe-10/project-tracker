<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
class AuditRegressionTest extends ComprehensiveUatTest
{
    private function requestData(array $body, int $user = 1) {
        $response = $this->withSession(['tracker_user'=>['id'=>$user],'tracker_csrf'=>'audit-token','tracker_created_at'=>time(),'tracker_last_activity'=>time()])->withHeader('X-CSRF-Token','audit-token')->postJson('/api/data.php',$body); return $this->finishImportFixture($response,$body);
    }
    private function imported(string $batch='audit_import_01'): array {
        $this->requestData(['action'=>'bulkSaveProjects','batchId'=>$batch,'records'=>[['title'=>'Original','projectNo'=>'123','status'=>'Pending']]])->assertOk();
        $row=DB::table('projects')->first();
        return json_decode($row->data,true)+['id'=>(int)$row->id];
    }
    public function test_rollback_preserves_later_edits(): void {
        $record=$this->imported();$record['title']='Later edit';
        $this->requestData(['action'=>'saveProject','record'=>$record])->assertOk();
        $this->requestData(['action'=>'rollbackImport','batchId'=>'audit_import_01'])->assertStatus(422);
        $this->assertSame('Later edit', DB::table('projects')->value('title'));
    }
    public function test_missing_or_stale_versions_are_rejected(): void {
        $record=$this->imported();$old=$record;
        unset($record['updatedAt']);$record['title']='Missing token';
        $this->requestData(['action'=>'saveProject','record'=>$record])->assertStatus(409);
        $old['title']='Valid edit';$this->requestData(['action'=>'saveProject','record'=>$old])->assertOk();
        $old['title']='Stale edit';$this->requestData(['action'=>'saveProject','record'=>$old])->assertStatus(409);
    }
    public function test_alert_only_staff_can_resolve_but_cannot_edit_project(): void {
        $record=$this->imported();
        DB::table('users')->insert(['id'=>2,'username'=>'resolver','full_name'=>'Resolver','password_hash'=>'hash','role'=>'staff','active'=>true,'can_resolve_alerts'=>true]);
        $record['timelineAlertsCompleted']=['noa-warning'];
        $stored=$record; unset($stored['id']);
        DB::table('projects')->where('id',$record['id'])->update(['data'=>json_encode($stored)]);
        $record['timelineAlertsCompleted']=[]; // Restore an existing historical dismissal; new manual resolutions are rejected.
        $saved=$this->requestData(['action'=>'saveProject','record'=>$record],2)->assertOk()->json('record');
        $saved['title']='Unauthorized';$this->requestData(['action'=>'saveProject','record'=>$saved],2)->assertForbidden();
        $this->assertSame('Original',DB::table('projects')->value('title'));
    }
    public function test_invalid_column_types_and_amounts_return_validation_errors(): void {
        $record=$this->imported();
        foreach ([['title'=>['nested']],['title'=>str_repeat('x',256)],['approvedBudget'=>'10000000000000'],['bidAmount'=>'not money']] as $bad) {
            $this->requestData(['action'=>'saveProject','record'=>array_replace($record,$bad)])->assertStatus(422)->assertJsonPath('ok',false);
        }
        $this->assertSame('0',\App\Http\Controllers\TrackerDataController::projectColumns(['projectNo'=>'0'])['project_no']);
    }
    public function test_expired_session_cannot_use_auth_admin_endpoint(): void {
        $this->withSession(['tracker_user'=>['id'=>1],'tracker_csrf'=>'audit-token','tracker_created_at'=>time()-28801,'tracker_last_activity'=>time()])->withHeader('X-CSRF-Token','audit-token')->postJson('/api/auth.php',['action'=>'listManagedUsers'])->assertUnauthorized();
    }
    public function test_regular_edits_preserve_attachment_archive_and_number_metadata(): void {
        $record=$this->imported();
        $record['attachments']=[['id'=>'keep','storedName'=>'keep.txt']];$record['archivedAt']='2026-01-01T00:00:00Z';
        DB::table('projects')->where('id',$record['id'])->update(['data'=>json_encode($record),'archived_at'=>'2026-01-01 00:00:00']);
        $incoming=$record;$incoming['attachments']=[];$incoming['archivedAt']='';$incoming['projectNo']='hijacked';$incoming['title']='Allowed edit';
        $saved=$this->requestData(['action'=>'saveProject','record'=>$incoming])->assertOk()->json('record');
        $this->assertSame($record['attachments'],$saved['attachments']);$this->assertSame($record['archivedAt'],$saved['archivedAt']);$this->assertSame('123',$saved['projectNo']);
    }
    public function test_failed_logins_lock_account_attempts_and_reject_bad_types(): void {
        if (!\Illuminate\Support\Facades\Schema::hasTable('login_attempts')) {
            \Illuminate\Support\Facades\Schema::create('login_attempts',function(\Illuminate\Database\Schema\Blueprint $table) {
                $table->string('attempt_key')->primary();$table->integer('failed_count')->default(0);$table->timestamp('first_failed_at');$table->timestamp('locked_until')->nullable();$table->timestamp('updated_at');
            });
        }
        $this->postJson('/api/auth.php',['action'=>'login','username'=>['bad'],'password'=>'wrong'])->assertStatus(422);
        for ($i=1;$i<=5;$i++) $this->postJson('/api/auth.php',['action'=>'login','username'=>'admin','password'=>'wrong'])->assertStatus($i===5 ? 429 : 401);
        $this->postJson('/api/auth.php',['action'=>'login','username'=>'admin','password'=>'wrong'])->assertStatus(429);
        $this->assertSame(5,(int)DB::table('login_attempts')->value('failed_count'));
    }

    public function test_service_folder_picker_is_read_only_and_validates_paths(): void {
        DB::table('users')->where('id',1)->update(['role'=>'admin']);
        if (PHP_OS_FAMILY !== 'Windows') $this->markTestSkipped('Windows server folder selection.');
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tracker-picker-'.bin2hex(random_bytes(5));
        mkdir($folder.'/child',0750,true);
        file_put_contents($folder.'/ordinary.txt','Do not list files.');
        $settingsPath = storage_path('app/backup-settings.json');
        $before = is_file($settingsPath) ? hash_file('sha256',$settingsPath) : null;
        try {
            $session = ['tracker_user'=>['id'=>1], 'tracker_csrf'=>'picker-token', 'tracker_created_at'=>time(), 'tracker_last_activity'=>time()];
            $this->withSession($session)->withHeader('X-CSRF-Token','picker-token')
                ->postJson('/api/backups.php',['action'=>'browseDestination','path'=>$folder])
                ->assertOk()->assertJsonPath('selected',false)->assertJsonPath('directories.0.name','child')
                ->assertJsonCount(1,'directories')->assertJsonPath('path',realpath($folder));
            foreach ([['invalid-array'], false, 123, 'relative-folder', $folder.'-missing', "D:\\\\bad\0path"] as $invalid) {
                $this->withSession($session)->withHeader('X-CSRF-Token','picker-token')
                    ->postJson('/api/backups.php',['action'=>'browseDestination','path'=>$invalid])->assertStatus(422);
            }
            $this->assertSame($before,is_file($settingsPath) ? hash_file('sha256',$settingsPath) : null);
        } finally { unlink($folder.'/ordinary.txt'); rmdir($folder.'/child'); rmdir($folder); }
    }

    public function test_service_folder_picker_requires_an_administrator(): void {
        DB::table('users')->insert(['id'=>2,'username'=>'picker-staff','full_name'=>'Picker Staff','password_hash'=>'hash','role'=>'staff','active'=>true]);
        $session = ['tracker_user'=>['id'=>2], 'tracker_csrf'=>'picker-token', 'tracker_created_at'=>time(), 'tracker_last_activity'=>time()];
        $this->withSession($session)->withHeader('X-CSRF-Token','picker-token')
            ->postJson('/api/backups.php',['action'=>'browseDestination','path'=>sys_get_temp_dir()])->assertForbidden();
    }
}
