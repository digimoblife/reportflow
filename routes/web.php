<?php

use App\Http\Controllers\Auth\DashboardLoginController;
use App\Http\Middleware\RequireSecureInProduction;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([RequireSecureInProduction::class, 'throttle:10,1'])->prefix('auth')->group(function () {
    Route::get('/telegram/callback', [DashboardLoginController::class, 'telegram'])->name('auth.telegram.callback');
    Route::get('/link/{token}', [DashboardLoginController::class, 'link'])->name('auth.link');
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});
