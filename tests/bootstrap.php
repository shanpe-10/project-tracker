<?php
// Test-only bootstrap: never use operational database rows or uploaded files.
$root=dirname(__DIR__);
$loader=require (getenv('TRACKER_TEST_AUTOLOAD') ?: $root.'/vendor/autoload.php');
$loader->setPsr4('App\\',$root.'/app'); $loader->setPsr4('Tests\\',$root.'/tests');
foreach ($loader->getClassMap() as $class=>$file) {
    foreach (['App\\'=>'app/','Tests\\'=>'tests/'] as $prefix=>$folder) {
        if (str_starts_with($class,$prefix)) {
            $replacement=$root.'/'.$folder.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if (is_file($replacement)) $loader->addClassMap([$class=>$replacement]);
        }
    }
}
$driver=getenv('DB_CONNECTION') ?: 'sqlite';
$database=$driver==='sqlite' ? ':memory:' : (getenv('DB_DATABASE') ?: 'tracker_audit_test');
if (!in_array($driver,['sqlite','pgsql'],true)||($driver==='pgsql'&&!str_ends_with($database,'_test'))) throw new RuntimeException('Tests require SQLite memory or a dedicated PostgreSQL database ending in _test.');
$temporary=sys_get_temp_dir().'/tracker-release-tests-'.bin2hex(random_bytes(8));
foreach (['app','bootstrap','views','framework/views','framework/sessions','framework/cache/data','logs','attachments','photos'] as $directory) mkdir($temporary.'/'.$directory,0750,true);
foreach (['APP_BASE_PATH'=>$root,'APP_URL'=>'http://localhost','APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(str_repeat('t',32)),
    'DB_CONNECTION'=>$driver,'DB_DATABASE'=>$database,'DB_URL'=>'','DB_SCHEMA'=>'public',
    'APP_CONFIG_CACHE'=>$temporary.'/bootstrap/config.php','APP_SERVICES_CACHE'=>$temporary.'/bootstrap/services.php',
    'APP_PACKAGES_CACHE'=>$temporary.'/bootstrap/packages.php','APP_ROUTES_CACHE'=>$temporary.'/bootstrap/routes.php',
    'VIEW_COMPILED_PATH'=>$temporary.'/views','SESSION_PATH'=>'/','SESSION_DOMAIN'=>'','SESSION_COOKIE'=>'tracker_test_session','SESSION_SECURE_COOKIE'=>'false','SESSION_DRIVER'=>'array','CACHE_STORE'=>'array','BCRYPT_ROUNDS'=>'4',
    'QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','TRACKER_TEST_STORAGE_PATH'=>$temporary,
    'TRACKER_ATTACHMENTS_PATH'=>$temporary.'/attachments','TRACKER_PROFILE_PHOTOS_PATH'=>$temporary.'/photos',
    'TRACKER_HOLIDAY_STATE_PATH'=>$temporary.'/holiday-sync.json'] as $key=>$value) {
    putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value;
}
register_shutdown_function(function () use ($temporary) {
    $resolved=realpath($temporary); $tempRoot=realpath(sys_get_temp_dir());
    if (!$resolved||!$tempRoot||!str_starts_with($resolved,$tempRoot.DIRECTORY_SEPARATOR.'tracker-release-tests-')) return;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isLink()) @unlink($file->getPathname()); elseif ($file->isDir()) @rmdir($file->getPathname()); else @unlink($file->getPathname()); }
    @rmdir($resolved);
});
