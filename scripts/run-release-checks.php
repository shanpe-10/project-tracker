<?php
// Uses configured local connection credentials, always overriding the database with a test database.
$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();
$env=array_merge(getenv(),$_ENV);
$database=getenv('TRACKER_TEST_DATABASE') ?: 'tracker_audit_test';
if (!str_ends_with($database,'_test')) throw new RuntimeException('A dedicated database ending in _test is required.');
$env['DB_CONNECTION']='pgsql'; $env['DB_DATABASE']=$database; $env['DB_URL']=''; $env['DB_SCHEMA']='public';
foreach (['USERNAME','PASSWORD','HOST','PORT'] as $part) if (getenv('TRACKER_TEST_'.$part)!==false) $env['DB_'.$part]=getenv('TRACKER_TEST_'.$part);
$commands=[[PHP_BINARY,$root.'/vendor/bin/phpunit','-c',$root.'/phpunit.pgsql.xml']];
$node=getenv('NODE_BINARY') ?: 'node';
$javascript=glob($root.'/tests/*.test.cjs');
$commands[]=array_merge([$node,'--test'],$javascript);
foreach ($commands as $command) {
    $process=proc_open($command,[STDIN,STDOUT,STDERR],$pipes,$root,$env,['bypass_shell'=>true,'create_new_console'=>false]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start a release check.');
    $exit=proc_close($process); if ($exit) exit($exit);
}
echo "PostgreSQL and JavaScript release checks passed.\n";
