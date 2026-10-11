<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('tracker_chat_members',function(Blueprint $t){$t->unsignedBigInteger('cleared_before_id')->default(0);});
  Schema::table('tracker_conversations',function(Blueprint $t){$t->unsignedBigInteger('history_revision')->default(0);});
 }
 public function down():void {Schema::table('tracker_chat_members',fn(Blueprint $t)=>$t->dropColumn('cleared_before_id'));Schema::table('tracker_conversations',fn(Blueprint $t)=>$t->dropColumn('history_revision'));}
};
