<?php

use App\Http\Controllers\Auth\DashboardLoginController;
use App\Http\Controllers\ReportDownloadController;
use App\Http\Middleware\RequireSecureInProduction;
use App\Services\Ops\HealthChecks;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([RequireSecureInProduction::class, 'throttle:10,1'])->prefix('auth')->group(function () {
    Route::get('/telegram/callback', [DashboardLoginController::class, 'telegram'])->name('auth.telegram.callback');
    Route::get('/link/{token}', [DashboardLoginController::class, 'link'])->name('auth.link');
});

Route::get('/reports/files/{file}', [ReportDownloadController::class, 'show'])
    ->middleware([RequireSecureInProduction::class, 'signed', 'throttle:30,1'])
    ->whereNumber('file')
    ->name('reports.files.download');

// For an external uptime monitor: 200 when the system works, 503 when something essential is down. Booleans only.
Route::get('/health/deep', function (HealthChecks $checks) {
    $healthy = $checks->healthy();

    return response()->json(['status' => $healthy ? 'ok' : 'degraded'], $healthy ? 200 : 503);
})->middleware('throttle:30,1');

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});
