<?php
// Initialize new installations while preserving an existing encryption key.
require __DIR__.'/../vendor/autoload.php';
$root=dirname(__DIR__);
if (!is_file($root.'/.env') && !copy($root.'/.env.example',$root.'/.env')) throw new RuntimeException('Could not create .env.');
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (trim((string)config('app.key')) === '') {
    Illuminate\Support\Facades\Artisan::call('key:generate', ['--force'=>true]);
    echo Illuminate\Support\Facades\Artisan::output();
} else echo 'Existing application key preserved.'.PHP_EOL;
