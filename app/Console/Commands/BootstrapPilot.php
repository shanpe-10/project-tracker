<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BootstrapPilot extends Command
{
    protected $signature = 'tracker:bootstrap-pilot';
    protected $description = 'Create the first superadmin in an empty pilot database from private environment variables';

    public function handle(): int
    {
        return DB::transaction(function (): int {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['tracker:create-superadmin']);
            if (DB::table('users')->exists()) {
                $this->info('Existing accounts retained. Pilot bootstrap skipped.');
                return self::SUCCESS;
            }
            $name = trim((string) getenv('TRACKER_BOOTSTRAP_NAME'));
            $username = trim((string) getenv('TRACKER_BOOTSTRAP_USERNAME'));
            $password = (string) getenv('TRACKER_BOOTSTRAP_PASSWORD');
            if ($name === '' || mb_strlen($name) > 160 || ! preg_match('/^[A-Za-z0-9._-]{3,80}$/D', $username)
                || mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
                $this->error('Set a valid TRACKER_BOOTSTRAP_NAME, USERNAME and PASSWORD in the private service environment. The password needs at least 12 characters and at most 72 bytes.');
                return self::FAILURE;
            }
            DB::table('users')->insert(['username'=>$username, 'full_name'=>$name, 'password_hash'=>Hash::make($password),
                'role'=>'superadmin', 'active'=>true, 'created_at'=>now()]);
            $this->info('First pilot superadmin created. Remove the bootstrap password from the service environment.');
            return self::SUCCESS;
        });
    }
}
