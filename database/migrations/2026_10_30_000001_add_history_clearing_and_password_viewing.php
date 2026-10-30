<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_encrypted')) {
            Schema::table('users', fn (Blueprint $table) => $table->text('password_encrypted')->nullable());
        }
        if (! Schema::hasTable('deletion_request_history_hidden')) {
            Schema::create('deletion_request_history_hidden', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('request_id')->constrained('deletion_requests')->cascadeOnDelete();
                $table->string('scope', 10);
                $table->timestamp('hidden_at');
                $table->primary(['user_id', 'scope', 'request_id'], 'deletion_history_hidden_pk');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_request_history_hidden');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_encrypted'));
    }
};
