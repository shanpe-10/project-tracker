<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deletion_requests')) Schema::create('deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 20);
            $table->boolean('bulk')->default(false);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->json('requester');
            $table->text('reason');
            $table->timestamp('requested_at');
            $table->json('reviewer')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('confirmation_hash', 64)->nullable();
            $table->json('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->index(['status', 'id']);
        });
        if (! Schema::hasTable('deletion_request_targets')) Schema::create('deletion_request_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('deletion_requests')->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('active_key', 80)->nullable()->unique();
            $table->string('fingerprint', 64);
            $table->json('snapshot');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_request_targets');
        Schema::dropIfExists('deletion_requests');
    }
};
