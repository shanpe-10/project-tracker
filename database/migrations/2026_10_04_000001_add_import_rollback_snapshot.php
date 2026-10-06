<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (!Schema::hasColumn('import_job_changes','applied_data')) Schema::table('import_job_changes',fn(Blueprint $table)=>$table->jsonb('applied_data')->nullable());
 }
 public function down(): void {
  if (Schema::hasColumn('import_job_changes','applied_data')) Schema::table('import_job_changes',fn(Blueprint $table)=>$table->dropColumn('applied_data'));
 }
};
