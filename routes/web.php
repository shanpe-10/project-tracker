<?php

use App\Http\Controllers\TrackerAuthController;
use App\Http\Controllers\TrackerBackupController;
use App\Http\Controllers\TrackerDataController;
use App\Http\Controllers\TrackerMediaController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'tracker');
Route::post('/api/auth.php', [TrackerAuthController::class, 'handle']);
Route::middleware('tracker.auth')->group(function () {
    Route::match(['get', 'post'], '/api/deletion-requests.php', [\App\Http\Controllers\TrackerDeletionController::class, 'handle']);
    Route::match(['get', 'post'], '/api/holidays.php', [\App\Http\Controllers\TrackerHolidayController::class, 'handle']);
    Route::match(['get', 'post'], '/api/project-presence.php', [\App\Http\Controllers\TrackerProjectPresenceController::class, 'handle']);
    Route::get('/api/data.php', [TrackerDataController::class, 'get']);
    Route::post('/api/data.php', [TrackerDataController::class, 'post']);
    Route::get('/api/attachments.php', [TrackerMediaController::class, 'attachment']);
    Route::post('/api/attachments.php', [TrackerMediaController::class, 'attachment']);
    Route::post('/api/project-edit.php', [TrackerMediaController::class, 'saveProjectEdit']);
    Route::get('/api/profile.php', [TrackerMediaController::class, 'profile']);
    Route::post('/api/profile.php', [TrackerMediaController::class, 'profile']);
    Route::get('/api/backups.php', [TrackerBackupController::class, 'handle']);
    Route::post('/api/backups.php', [TrackerBackupController::class, 'handle']);
});
