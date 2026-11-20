<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('password_encrypted')->update(['password_encrypted' => null]);
    }

    public function down(): void
    {
        // The discarded copies are intentionally unrecoverable.
    }
};
