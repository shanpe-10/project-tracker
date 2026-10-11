<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('tracker_chat_members',function(Blueprint $t){$t->boolean('archived')->default(false);});
  Schema::create('tracker_chat_presence',function(Blueprint $t){$t->unsignedBigInteger('user_id')->primary();$t->timestamp('last_active_at');});
 }
 public function down():void {Schema::dropIfExists('tracker_chat_presence');Schema::table('tracker_chat_members',function(Blueprint $t){$t->dropColumn('archived');});}
};
