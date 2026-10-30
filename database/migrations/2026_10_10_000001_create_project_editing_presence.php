<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('project_editing_presence')) Schema::create('project_editing_presence', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->string('editor_token', 32);
            $table->unsignedBigInteger('project_id');
            $table->string('session_key', 64);
            $table->timestamp('last_seen_at');
            $table->timestamp('ended_at')->nullable();
            $table->primary(['user_id', 'editor_token']);
            $table->index(['project_id', 'last_seen_at']);
            $table->index('session_key');
            $table->index('last_seen_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_editing_presence');
    }
};
