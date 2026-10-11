<?php

use App\Http\Controllers\TrackerBackupController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tracker:backup', function () {
    $archive = app(TrackerBackupController::class)->runScheduledBackup();
    $this->info($archive ? "Backup created: $archive" : 'No backup was due.');
})->purpose('Create the daily project-data backup when automatic backups are enabled');

Artisan::command('tracker:create-admin', function () {
    if (! Schema::hasTable('users')) {
        $this->error('Set up the tracker database before creating an administrator.');
        return 1;
    }
    if (DB::table('users')->where('role', 'admin')->exists()) {
        $this->error('An administrator already exists. Sign in with that account.');
        return 1;
    }
    $name = trim((string) $this->ask('Administrator full name'));
    $username = trim((string) $this->ask('Administrator username'));
    $password = (string) $this->secret('Password (at least 12 characters)');
    $confirmation = (string) $this->secret('Confirm password');
    if ($name === '' || mb_strlen($name) > 160 || ! preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0") || $password !== $confirmation) {
        $this->error('Check the name, username, and matching password of at least 12 characters and at most 72 bytes.');
        return 1;
    }
    DB::transaction(function () use ($name, $username, $password) {
        if (DB::connection()->getDriverName() === 'pgsql') DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['tracker:create-admin']);
        if (DB::table('users')->where('role', 'admin')->lockForUpdate()->exists()) {
            throw new \RuntimeException('An administrator was created while this command was running.');
        }
        DB::table('users')->insert([
            'username' => $username, 'full_name' => $name,
            'password_hash' => Hash::make($password), 'role' => 'admin',
            'active' => true, 'created_at' => now(),
        ]);
    });
    $this->info('Administrator created. You can now sign in to the tracker.');
    return 0;
})->purpose('Create the first tracker administrator on a new installation');

Artisan::command('tracker:create-superadmin', function () {
    if (! Schema::hasTable('users')) {
        $this->error('Set up the tracker database before creating an Superadmin.');
        return 1;
    }
    if (DB::table('users')->where('role', 'superadmin')->exists()) {
        $this->error('An Superadmin already exists. Sign in with that account.');
        return 1;
    }
    $name = trim((string) $this->ask('Superadmin full name'));
    $username = trim((string) $this->ask('Superadmin username'));
    $password = (string) $this->secret('Password (at least 12 characters)');
    $confirmation = (string) $this->secret('Confirm password');
    if ($name === '' || mb_strlen($name) > 160 || ! preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0") || $password !== $confirmation) {
        $this->error('Check the name, username, and matching password of at least 12 characters and at most 72 bytes.');
        return 1;
    }
    DB::transaction(function () use ($name, $username, $password) {
        if (DB::connection()->getDriverName() === 'pgsql') DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['tracker:create-superadmin']);
        if (DB::table('users')->where('role', 'superadmin')->lockForUpdate()->exists()) {
            throw new \RuntimeException('An Superadmin was created while this command was running.');
        }
        DB::table('users')->insert([
            'username' => $username, 'full_name' => $name,
            'password_hash' => Hash::make($password), 'role' => 'superadmin',
            'active' => true, 'created_at' => now(),
        ]);
    });
    $this->info('Superadmin created. You can now sign in to the tracker.');
    return 0;
})->purpose('Create the first tracker Superadmin on a new installation');

$backupSchedule = Schedule::command('tracker:backup')->timezone('Asia/Taipei')->withoutOverlapping(20);
if (config('tracker.cloud')) $backupSchedule->hourlyAt(5);
else $backupSchedule->dailyAt('02:00');


Artisan::command('tracker:holidays-sync {--force : Check now even if already checked today or disabled}', function () {
    try {
        $result = \App\Support\TrackerHolidays::sync((bool)$this->option('force'));
        $this->info('Holiday calendar checked. Last success: '.($result['state']['lastSuccess'] ?? 'not yet synced'));
        return 0;
    } catch (\Throwable $error) { $this->error($error->getMessage()); return 1; }
})->purpose('Sync declared national Philippine non-working holidays from the official PCO feed');

Schedule::command('tracker:holidays-sync')->hourlyAt(0)->timezone('Asia/Manila')->withoutOverlapping(20)
    ->when(fn () => now('Asia/Manila')->hour >= 3);

Artisan::command('tracker:project-status-sync', function () {
    $changed=0;
    DB::table('projects')->whereNull('archived_at')->select('id')->orderBy('id')->chunkById(200,function($rows)use(&$changed){
        foreach($rows as $item) DB::transaction(function()use($item,&$changed){
            $row=DB::table('projects')->where('id',$item->id)->lockForUpdate()->first();if(!$row)return;
            $record=json_decode($row->data,true)?:[];
            $status=\App\Support\TrackerProjectInput::automaticStatus($record);
            if(($record['status']??'')===$status)return;
            $record['status']=$status;$record['updatedAt']=now('UTC')->format('Y-m-d\TH:i:s.v\Z');
            DB::table('projects')->where('id',$row->id)->update(['data'=>json_encode($record,JSON_UNESCAPED_UNICODE),'project_status'=>$status,'updated_at'=>now()]);
            DB::table('audit_log')->insert(['actor_id'=>null,'actor_username'=>'System','action'=>'project.status.auto','entity_type'=>'project','entity_id'=>$row->id,'summary'=>'Automatically changed status to '.$status.'.','created_at'=>now()]);
            $changed++;
        });
    });
    $this->info('Updated '.$changed.' project statuses.');
});
Schedule::command('tracker:project-status-sync')->everyMinute()->withoutOverlapping(5);
