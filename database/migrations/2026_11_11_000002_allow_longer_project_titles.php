<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('title', 400)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('projects')->whereRaw('length(title) > 255')->exists()) {
            throw new RuntimeException('Existing titles exceed 255 characters.');
        }
        Schema::table('projects', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->change();
        });
    }
};
