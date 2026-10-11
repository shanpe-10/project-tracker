<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tracker_conversations', function (Blueprint $t) {
            $t->id(); $t->string('name',100)->nullable(); $t->boolean('is_group')->default(false);
            $t->unsignedBigInteger('created_by'); $t->string('direct_key')->nullable()->unique(); $t->timestamps();
        });
        Schema::create('tracker_chat_members', function (Blueprint $t) {
            $t->unsignedBigInteger('conversation_id'); $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('last_read_id')->default(0); $t->primary(['conversation_id','user_id']); $t->index('user_id');
            $t->foreign('conversation_id')->references('id')->on('tracker_conversations')->cascadeOnDelete();
        });
        Schema::create('tracker_messages', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('conversation_id'); $t->unsignedBigInteger('user_id');
            $t->text('body'); $t->timestamp('created_at'); $t->index(['conversation_id','id']);
            $t->foreign('conversation_id')->references('id')->on('tracker_conversations')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('tracker_messages'); Schema::dropIfExists('tracker_chat_members'); Schema::dropIfExists('tracker_conversations'); }
};
