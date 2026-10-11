<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tracker_account_sessions')) return;
        Schema::create('tracker_account_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->char('token_hash', 64);
            $table->bigInteger('started_at');
            $table->bigInteger('last_activity_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_account_sessions');
    }
};
