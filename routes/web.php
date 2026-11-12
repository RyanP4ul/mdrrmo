<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\CommunicationCallController;
use App\Http\Controllers\Dispatcher;
use App\Http\Controllers\Driver;
use App\Http\Controllers\DriverFormController;
use App\Http\Controllers\GuestReportController;
use App\Http\Controllers\Resident;
use App\Http\Controllers\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:resident,dispatcher,driver/responder'])
    ->prefix('communication/calls')->name('communication.calls.')->group(function (): void {
        Route::get('/', [CommunicationCallController::class, 'index'])->name('index');
        Route::post('/', [CommunicationCallController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::post('/sos', [CommunicationCallController::class, 'sos'])->middleware('throttle:10,1')->name('sos');
        Route::post('/availability', [CommunicationCallController::class, 'availability'])->middleware('throttle:30,1');
        Route::post('/{call}/manage', [CommunicationCallController::class, 'manage'])->middleware('throttle:30,1');
        Route::get('/{call}', [CommunicationCallController::class, 'show'])->name('show');
        Route::post('/{call}/token', [CommunicationCallController::class, 'token'])->middleware('throttle:30,1')->name('token');
        Route::post('/{call}/end', [CommunicationCallController::class, 'end'])->middleware('throttle:10,1')->name('end');
    });

Route::get('/', function () {
    return inertia('Login');
})->name('login');

Route::post('/api/auth/login', [WebAuthController::class, 'authenticate'])
    ->middleware('throttle:5,1')
    ->name('api.auth.login');
Route::get('/guest/report', [GuestReportController::class, 'create'])->name('guest.report');
Route::post('/logout', [WebAuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/dashboard', fn () => redirect()->route(match (auth()->user()->role) {
    'admin' => 'admin.dashboard',
    'dispatcher' => 'dispatcher.dashboard',
    'driver/responder' => 'driver.dashboard',
    'resident' => 'resident.dashboard',
}))->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');
    Route::get('reports/operations/summary', [Admin\EmergencyReportController::class, 'operationsSummary'])->name('reports.operations.summary');
    Route::get('reports/operations/pdf', [Admin\EmergencyReportController::class, 'operationsPdf'])->name('reports.operations.pdf');
    Route::get('reports/{report}/driver-form', DriverFormController::class)->name('reports.driver-form');
    Route::resource('reports', Admin\EmergencyReportController::class)->only(['index', 'show', 'update']);
    Route::resource('users', Admin\UserController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['users' => 'account']);
    Route::patch('residents/{account}/review', [Admin\UserController::class, 'review'])->name('residents.review');
    Route::get('response-teams', [Admin\ResponseTeamController::class, 'index'])->name('response-teams.index');
    Route::resource('vehicles', Admin\VehicleController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::patch('vehicles/{vehicle}/status', [Admin\VehicleController::class, 'updateStatus'])->name('vehicles.status');
    Route::patch('maintenance-reports/{maintenanceReport}/review', [Admin\VehicleController::class, 'reviewMaintenanceReport'])->name('maintenance-reports.review');
    Route::resource('incident-types', Admin\IncidentTypeController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('audit-logs', [Admin\AuditLogController::class, 'index'])->name('audit-logs.index');
});

// ── Dispatcher ───────────────────────────────────────────────────
Route::middleware(['auth', 'role:dispatcher'])->prefix('dispatcher')->name('dispatcher.')->group(function () {
    Route::get('/', [Dispatcher\DashboardController::class, 'index'])->name('dashboard');
    Route::post('dispatch', [Dispatcher\DashboardController::class, 'store'])->name('dispatch.store');
    Route::get('reports', [Dispatcher\ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}/driver-form', DriverFormController::class)->name('reports.driver-form');
    Route::get('reports/{report}', [Dispatcher\ReportController::class, 'show'])->name('reports.show');
    Route::post('reports/{report}/assign', [Dispatcher\ReportController::class, 'assign'])->name('reports.assign');
    Route::patch('reports/{report}/acknowledge', [Dispatcher\ReportController::class, 'acknowledge'])->name('reports.acknowledge');
    Route::patch('reports/{report}/invalid', [Dispatcher\ReportController::class, 'invalidate'])->name('reports.invalid');
    Route::get('responders', [Dispatcher\ResponderController::class, 'index'])->name('responders.index');
    Route::post('responders', [Dispatcher\ResponderController::class, 'store'])->name('responders.store');
    Route::put('responders/{team}', [Dispatcher\ResponderController::class, 'update'])->name('responders.update');
    Route::delete('responders/{team}', [Dispatcher\ResponderController::class, 'destroy'])->name('responders.destroy');
    Route::post('responders/{team}/shifts', [Dispatcher\ResponderController::class, 'storeShift'])->name('shifts.store');
    Route::patch('responders/{team}/shifts/{shift}', [Dispatcher\ResponderController::class, 'updateShift'])
        ->scopeBindings()
        ->name('shifts.update');
    Route::get('map', [Dispatcher\MapController::class, 'index'])->name('map');
    Route::get('schedule', [Dispatcher\ScheduleController::class, 'index'])->name('schedule.index');
    Route::patch('schedule/{schedule}/acknowledge', [Dispatcher\ScheduleController::class, 'acknowledge'])->name('schedule.acknowledge');
    Route::patch('schedule/{schedule}/decline', [Dispatcher\ScheduleController::class, 'decline'])->name('schedule.decline');
});

// ── Driver / responder ───────────────────────────────────────────
Route::middleware(['auth', 'role:driver/responder'])->prefix('driver')->name('driver.')->group(function () {
    Route::get('/', [Driver\DriverController::class, 'dashboard'])->name('dashboard');
    Route::get('assignments', [Driver\DriverController::class, 'assignments'])->name('assignments');
    Route::patch('assignments/{report}', [Driver\DriverController::class, 'updateStatus'])->name('assignments.status');
    Route::get('reports', [Driver\DriverController::class, 'reports'])->name('reports');
    Route::put('reports/{report}/form', [Driver\DriverController::class, 'saveForm'])->name('reports.form');
    Route::get('team', [Driver\DriverController::class, 'team'])->name('team');
    Route::patch('teams/{team}/role', [Driver\DriverController::class, 'changeTeamRole'])->name('teams.role');
    Route::patch('teams/{team}/vehicle', [Driver\DriverController::class, 'switchVehicle'])->name('teams.vehicle');
    Route::post('maintenance-reports', [Driver\DriverController::class, 'storeMaintenanceReport'])->name('maintenance-reports.store');
    Route::get('tracking', [Driver\DriverController::class, 'tracking'])->name('tracking');
    Route::post('ping', [Driver\DriverController::class, 'ping'])->middleware('throttle:30,1')->name('ping');
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
});
