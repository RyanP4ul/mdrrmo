<?php

use App\Http\Controllers\Admin\ResponseTeamController;
use App\Http\Controllers\Dispatcher\ReportController;
use App\Http\Controllers\MobileDriverApiController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\MobileResidentApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/mobile/login', [MobileAuthController::class, 'authenticate'])->name('api.auth.mobile.login');
Route::post('/auth/mobile/register', [MobileAuthController::class, 'register'])->name('api.auth.mobile.register');

Route::middleware('auth:sanctum')->prefix('auth/mobile')->name('api.auth.mobile.')->group(function () {
    Route::get('/me', [MobileAuthController::class, 'me'])->name('me');
    Route::post('/logout', [MobileAuthController::class, 'logout'])->name('logout');
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/tokens/create', function (Request $request) {
    $data = $request->validate(['token_name' => ['required', 'string', 'max:255']]);
    $token = $request->user()->createToken($data['token_name']);

    return ['token' => $token->plainTextToken];
})->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'role:driver/responder'])
    ->prefix('driver-responder')
    ->name('api.driver-responder.')
    ->group(function () {
        Route::get('/bootstrap', [MobileDriverApiController::class, 'bootstrap'])->name('bootstrap');
        Route::get('/assignments', [MobileDriverApiController::class, 'assignments'])->name('assignments');
        Route::get('/history', [MobileDriverApiController::class, 'history'])->name('history');
        Route::patch('/assignments/{report}/status', [MobileDriverApiController::class, 'updateStatus'])->name('assignments.status');
        Route::patch('/availability', [MobileDriverApiController::class, 'updateAvailability'])->name('availability');
        Route::put('/profile', [MobileDriverApiController::class, 'updateProfile'])->name('profile.update');
        Route::get('/incident-types', [MobileDriverApiController::class, 'incidentTypes'])->name('incident-types');
        Route::post('/reports', [MobileDriverApiController::class, 'storeReport'])->name('reports.store');
        Route::get('/teams', [ResponseTeamController::class, 'getTeams'])->name('teams');
        Route::get('/reports', [ReportController::class, 'getReports'])->name('reports');
    });

Route::middleware(['auth:sanctum', 'role:resident'])
    ->prefix('resident')
    ->name('api.resident.')
    ->group(function () {
        Route::get('/bootstrap', [MobileResidentApiController::class, 'bootstrap'])->name('bootstrap');
        Route::post('/reports', [MobileResidentApiController::class, 'storeReport'])->name('reports.store');
        Route::post('/schedules', [MobileResidentApiController::class, 'storeSchedule'])->name('schedules.store');
        Route::patch('/notifications/read', [MobileResidentApiController::class, 'markAllRead'])->name('notifications.read-all');
        Route::patch('/notifications/{notification}/read', [MobileResidentApiController::class, 'markRead'])->name('notifications.read');
    });
