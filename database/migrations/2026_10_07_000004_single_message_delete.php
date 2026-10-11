<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('tracker_chat_members',fn(Blueprint $t)=>$t->unsignedBigInteger('message_revision')->default(0));
  Schema::create('tracker_hidden_messages',function(Blueprint $t){$t->unsignedBigInteger('message_id');$t->unsignedBigInteger('user_id');$t->primary(['message_id','user_id']);$t->foreign('message_id')->references('id')->on('tracker_messages')->cascadeOnDelete();});
 }
 public function down():void {Schema::dropIfExists('tracker_hidden_messages');Schema::table('tracker_chat_members',fn(Blueprint $t)=>$t->dropColumn('message_revision'));}
};
