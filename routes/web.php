<?php

use App\Http\Controllers\{Admin, AnnouncementFeedController, Dispatcher, Driver, Resident, WebAuthController};
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return inertia('Login');
})->name("login");

Route::post('/api/auth/login', [WebAuthController::class, 'authenticate'])->name('api.auth.login');

Route::get('/dashboard', fn () => redirect()->route(match (auth()->user()->role) {
    'admin' => 'admin.dashboard',
    'dispatcher' => 'dispatcher.dashboard',
    'driver/responder' => 'driver.dashboard',
    'resident' => 'resident.dashboard',
}))->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', Admin\DashboardController::class)->name('dashboard');
        Route::resource('reports', Admin\EmergencyReportController::class)->only(['index', 'show', 'update']);
        Route::resource('users', Admin\UserController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['users' => 'account']);
        Route::patch('residents/{account}/review', [Admin\UserController::class, 'review'])->name('residents.review');
        Route::get('response-teams', [Admin\ResponseTeamController::class, 'index'])->name('response-teams.index');
        Route::resource('incident-types', Admin\IncidentTypeController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('announcements', Admin\AnnouncementController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('audit-logs', [Admin\AuditLogController::class, 'index'])->name('audit-logs.index');
    });

    // ── Dispatcher ───────────────────────────────────────────────────
    Route::middleware(['auth', 'role:dispatcher'])->prefix('dispatcher')->name('dispatcher.')->group(function () {
        Route::get('/', [Dispatcher\DashboardController::class, 'index'])->name('dashboard');
        Route::post('dispatch', [Dispatcher\DashboardController::class, 'store'])->name('dispatch.store');
        Route::get('reports', [Dispatcher\ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}', [Dispatcher\ReportController::class, 'show'])->name('reports.show');
        Route::post('reports/{report}/assign', [Dispatcher\ReportController::class, 'assign'])->name('reports.assign');
        Route::patch('reports/{report}/acknowledge', [Dispatcher\ReportController::class, 'acknowledge'])->name('reports.acknowledge');
        Route::patch('reports/{report}/invalid', [Dispatcher\ReportController::class, 'invalidate'])->name('reports.invalid');
        Route::get('responders', [Dispatcher\ResponderController::class, 'index'])->name('responders.index');
        Route::post('responders', [Dispatcher\ResponderController::class, 'store'])->name('responders.store');
        Route::put('responders/{team}', [Dispatcher\ResponderController::class, 'update'])->name('responders.update');
        Route::delete('responders/{team}', [Dispatcher\ResponderController::class, 'destroy'])->name('responders.destroy');
        Route::post('responders/{team}/shifts', [Dispatcher\ResponderController::class, 'storeShift'])->name('shifts.store');
        Route::patch('shifts/{shift}', [Dispatcher\ResponderController::class, 'updateShift'])->name('shifts.update');
        Route::get('map', [Dispatcher\MapController::class, 'index'])->name('map');
        Route::get('schedule', [Dispatcher\ScheduleController::class, 'index'])->name('schedule.index');
        Route::patch('schedule/{schedule}/acknowledge', [Dispatcher\ScheduleController::class, 'acknowledge'])->name('schedule.acknowledge');
        Route::patch('schedule/{schedule}/decline', [Dispatcher\ScheduleController::class, 'decline'])->name('schedule.decline');
        Route::get('announcements', AnnouncementFeedController::class)->name('announcements');
    });

    // ── Driver / responder ───────────────────────────────────────────
    Route::middleware(['auth', 'role:driver/responder'])->prefix('driver')->name('driver.')->group(function () {
        Route::get('/', [Driver\DriverController::class, 'dashboard'])->name('dashboard');
        Route::get('assignments', [Driver\DriverController::class, 'assignments'])->name('assignments');
        Route::patch('assignments/{report}', [Driver\DriverController::class, 'updateStatus'])->name('assignments.status');
        Route::get('reports', [Driver\DriverController::class, 'reports'])->name('reports');
        Route::put('reports/{report}/form', [Driver\DriverController::class, 'saveForm'])->name('reports.form');
        Route::get('tracking', [Driver\DriverController::class, 'tracking'])->name('tracking');
        Route::post('ping', [Driver\DriverController::class, 'ping'])->name('ping');
    });

    // ── Resident ─────────────────────────────────────────────────────
    Route::middleware(['auth', 'role:resident'])->prefix('resident')->name('resident.')->group(function () {
        Route::get('/', [Resident\ResidentController::class, 'dashboard'])->name('dashboard');
        Route::get('report', [Resident\ResidentController::class, 'createReport'])->name('report');
        Route::post('report', [Resident\ResidentController::class, 'storeReport'])->name('report.store');
        Route::get('history', [Resident\ResidentController::class, 'history'])->name('history');
        Route::get('schedule', [Resident\ResidentController::class, 'schedule'])->name('schedule');
        Route::post('schedule', [Resident\ResidentController::class, 'storeSchedule'])->name('schedule.store');
        Route::patch('schedule/{schedule}/cancel', [Resident\ResidentController::class, 'cancelSchedule'])->name('schedule.cancel');
        Route::get('notifications', [Resident\ResidentController::class, 'notifications'])->name('notifications');
        Route::patch('notifications/{notification?}/read', [Resident\ResidentController::class, 'markRead'])->name('notifications.read');
        Route::get('announcements', AnnouncementFeedController::class)->name('announcements');
    });
