<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  if (DB::connection()->getDriverName() !== 'pgsql') throw new RuntimeException('Tracker migrations require PostgreSQL.');
  DB::unprepared(file_get_contents(database_path('lgu_project_tracker_schema.sql')));
 }
 public function down(): void {
  foreach (['import_staging_chunks','deletion_request_history_hidden','deletion_request_targets','deletion_requests','project_editing_presence','tracker_account_sessions','import_job_changes','import_jobs','project_number_sequences','audit_log','application_settings','history','projects','login_attempts','users'] as $table) DB::statement('DROP TABLE IF EXISTS "'.$table.'"');
  DB::statement('DROP FUNCTION IF EXISTS tracker_touch_updated_at()');
  DB::statement('DROP FUNCTION IF EXISTS tracker_date(text)');
  DB::statement('DROP COLLATION IF EXISTS tracker_ci');
 }
};
