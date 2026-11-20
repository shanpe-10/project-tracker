<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            $postgres = DB::connection()->getDriverName() === 'pgsql';
            if ($postgres) DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');

            DB::table('users')->where('role', 'superadmin')->update(['role' => 'admin']);

            if ($postgres) {
                DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
                DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'staff'))");
            }
        });
    }

    public function down(): void
    {
        // Restore the previous constraint. Account role rollback requires the pre-upgrade account backup.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('superadmin', 'admin', 'staff'))");
        }
    }
};
