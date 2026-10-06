<?php
// One-time transfer only. Runtime application no longer uses MySQL.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$target = Illuminate\Support\Facades\DB::connection();
if ($target->getDriverName() !== 'pgsql') throw new RuntimeException('Target must be PostgreSQL.');
$sourceDb = getenv('SOURCE_DB_DATABASE');
$sourceUser = getenv('SOURCE_DB_USERNAME');
if (!$sourceDb || !$sourceUser) throw new RuntimeException('Set SOURCE_DB_DATABASE and SOURCE_DB_USERNAME.');
$secretFile = getenv('SOURCE_SECRETS_FILE');
$secrets = $secretFile && is_file($secretFile) ? require $secretFile : [];
$source = new PDO('mysql:host='.(getenv('SOURCE_DB_HOST') ?: '127.0.0.1').';port='.(getenv('SOURCE_DB_PORT') ?: '3306').';dbname='.$sourceDb.';charset=utf8mb4', $sourceUser, getenv('SOURCE_DB_PASSWORD') ?: ($secrets['database_password'] ?? ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$source->exec("SET time_zone = '+00:00'");
$source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$tables = ['users','login_attempts','projects','history','application_settings','audit_log','project_number_sequences','import_jobs','import_job_changes'];
$booleans = ['active','can_create','can_edit','can_import','can_export','can_delete','can_delete_all','can_resolve_alerts','can_archive','can_edit_alerts'];
try {
 $target->transaction(function () use ($target, $source, $tables, $booleans) {
  foreach ($tables as $table) {
   if ($table !== 'project_number_sequences' && $target->table($table)->exists()) throw new RuntimeException('Target must be empty: '.$table);
  }
  $target->table('project_number_sequences')->delete();
  foreach ($tables as $table) {
   $query = $source->query('SELECT * FROM `'.$table.'`');
   $count = 0;
   while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    foreach ($booleans as $column) if (array_key_exists($column,$row)) $row[$column]=(bool)$row[$column];
    // Invalid dates, JSON, duplicate keys and FK failures abort the entire transfer.
    $target->table($table)->insert($row); ++$count;
   }
   if ($target->table($table)->count() !== $count) throw new RuntimeException('Row count mismatch: '.$table);
   echo $table.': '.$count.' rows'.PHP_EOL;
  }
  foreach (['users','projects','history','audit_log','import_job_changes'] as $table) {
   $target->statement("SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM \"$table\"),1), EXISTS(SELECT 1 FROM \"$table\"))", [$table]);
  }
  $highest = (int)$target->table('projects')->whereRaw(App\Support\TrackerSql::numericPredicate())->max(Illuminate\Support\Facades\DB::raw(App\Support\TrackerSql::number()));
  $target->table('project_number_sequences')->upsert([['sequence_name'=>'projects','next_number'=>max($highest+1,(int)$target->table('project_number_sequences')->where('sequence_name','projects')->value('next_number'))]], ['sequence_name'], ['next_number']);
 });
 $source->commit();
 echo 'Transfer committed; identities reset. Keep the source backup until acceptance.'.PHP_EOL;
} catch (Throwable $error) {
 if ($source->inTransaction()) $source->rollBack();
 fwrite(STDERR, 'Transfer failed and target transaction rolled back: '.$error->getMessage().PHP_EOL);
 exit(1);
}
