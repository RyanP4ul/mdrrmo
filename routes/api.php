<?php

use App\Http\Controllers\Admin\ResponseTeamController;
use App\Http\Controllers\CommunicationCallController;
use App\Http\Controllers\Dispatcher\ReportController;
use App\Http\Controllers\GuestReportController;
use App\Http\Controllers\MobileAccountController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\MobileCatalogController;
use App\Http\Controllers\MobileDriverApiController;
use App\Http\Controllers\MobileDriverLocationController;
use App\Http\Controllers\MobileFleetController;
use App\Http\Controllers\MobileLocationController;
use App\Http\Controllers\MobileOperationsController;
use App\Http\Controllers\MobileRealtimeController;
use App\Http\Controllers\MobileRecordsController;
use App\Http\Controllers\MobileResidentApiController;
use App\Http\Controllers\MobileStaffAccountsController;
use App\Models\DispatcherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/connectivity', function (Request $request): JsonResponse {
    $data = ['service' => 'mdrrmo'];

    if ($request->boolean('include_contact')) {
        try {
            $data['dispatcher_phone'] = DispatcherUser::where('status', 'active')->whereNotNull('phone_number')->where('phone_number', '!=', '')->orderBy('id')->value('phone_number');
        } catch (Throwable $exception) {
            report($exception);
            $data['dispatcher_phone'] = null;
        }
    }

    return response()->json($data)->header('Cache-Control', 'no-store');
});

Route::prefix('guest')->name('api.guest.')->group(function (): void {
    Route::post('/calls', [CommunicationCallController::class, 'guestSos'])->middleware('throttle:10,1');
    Route::get('/calls/{call}', [CommunicationCallController::class, 'guestShow'])->middleware('throttle:60,1');
    Route::post('/calls/{call}/token', [CommunicationCallController::class, 'guestToken'])->middleware('throttle:30,1');
    Route::post('/calls/{call}/end', [CommunicationCallController::class, 'guestEnd'])->middleware('throttle:10,1');
    Route::get('/incident-types', [GuestReportController::class, 'types'])->middleware('throttle:60,1')->name('incident-types');
    Route::post('/reports', [GuestReportController::class, 'store'])
        ->middleware(['throttle:guest-reports', 'mobile.idempotent'])->name('reports.store');
});

Route::middleware(['auth:sanctum', 'mobile.session', 'abilities:session'])
    ->prefix('communication/calls')->name('api.communication.calls.')->group(function (): void {
        Route::get('/', [CommunicationCallController::class, 'index'])->name('index');
        Route::post('/', [CommunicationCallController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::post('/sos', [CommunicationCallController::class, 'sos'])->middleware('throttle:10,1')->name('sos');
        Route::post('/availability', [CommunicationCallController::class, 'availability'])->middleware('throttle:30,1');
        Route::post('/{call}/manage', [CommunicationCallController::class, 'manage'])->middleware('throttle:30,1');
        Route::get('/{call}', [CommunicationCallController::class, 'show'])->name('show');
        Route::post('/{call}/token', [CommunicationCallController::class, 'token'])->middleware('throttle:30,1')->name('token');
        Route::post('/{call}/end', [CommunicationCallController::class, 'end'])->middleware('throttle:10,1')->name('end');
    });

Route::post('/auth/mobile/forgot-password', [MobileAccountController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/auth/mobile/reset-password', [MobileAccountController::class, 'reset'])->middleware('throttle:5,1');

Route::get('/auth/mobile/policy', [MobileAuthController::class, 'policy'])->name('api.auth.mobile.policy');

Route::post('/auth/mobile/login', [MobileAuthController::class, 'authenticate'])->middleware('throttle:mobile-login')->name('api.auth.mobile.login');
Route::post('/auth/mobile/register', [MobileAuthController::class, 'register'])->middleware(['throttle:mobile-register', 'mobile.idempotent'])->name('api.auth.mobile.register');

Route::middleware(['auth:sanctum', 'mobile.session', 'abilities:session'])->prefix('auth/mobile')->name('api.auth.mobile.')->group(function () {
    Route::get('/account', [MobileAccountController::class, 'show']);
    Route::put('/account', [MobileAccountController::class, 'update']);
    Route::get('/avatar', [MobileAccountController::class, 'avatar']);
    Route::post('/avatar', [MobileAccountController::class, 'uploadAvatar'])->middleware('throttle:10,1');
    Route::put('/password', [MobileAccountController::class, 'changePassword'])->middleware('throttle:5,1');
    Route::put('/deletion-request', [MobileAccountController::class, 'deletion'])->middleware('throttle:5,1');
    Route::get('/me', [MobileAuthController::class, 'me'])->name('me');
    Route::post('/logout', [MobileAuthController::class, 'logout'])->name('logout');
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'mobile.session', 'role:driver/responder'])
    ->prefix('driver-responder')
    ->name('api.driver-responder.')
    ->group(function () {
        Route::get('/realtime', [MobileRealtimeController::class, 'config'])->middleware('abilities:driver-read');
        Route::post('/broadcasting/auth', [MobileRealtimeController::class, 'authenticate'])->middleware(['abilities:driver-read', 'throttle:60,1']);
        Route::get('/fleet', [MobileRealtimeController::class, 'fleet'])->middleware('abilities:driver-read');
        Route::get('/authored-reports', [MobileRecordsController::class, 'authored'])->middleware('abilities:driver-read');
        Route::get('/notifications', [MobileRecordsController::class, 'inbox'])->middleware('abilities:driver-read');
        Route::patch('/notifications/{notification}/read', [MobileRecordsController::class, 'readInbox'])->middleware('abilities:driver-write');
        Route::get('/schedules', [MobileRecordsController::class, 'driverSchedules'])->middleware('abilities:driver-read');
        Route::patch('/schedules/{schedule}/status', [MobileRecordsController::class, 'progressDriverSchedule'])->middleware('abilities:driver-write');
        Route::patch('/schedules/{schedule}/complete', [MobileRecordsController::class, 'completeDriverSchedule'])->middleware('abilities:driver-write');
        Route::get('/schedules/{schedule}', [MobileRecordsController::class, 'driverSchedule'])->middleware('abilities:driver-read');
        Route::get('/reports/{report}', [MobileRecordsController::class, 'report'])->middleware('abilities:driver-read');
        Route::get('/reports/{report}/patient', [MobileRecordsController::class, 'patient'])->middleware('abilities:driver-read');
        Route::put('/reports/{report}/patient', [MobileRecordsController::class, 'savePatient'])->middleware('abilities:driver-write');
        Route::put('/reports/{report}/trip', [MobileRecordsController::class, 'saveTrip'])->middleware('abilities:driver-write');
        Route::get('/bootstrap', [MobileDriverApiController::class, 'bootstrap'])->middleware(['abilities:driver-read'])->name('bootstrap');
        Route::get('/assignments', [MobileDriverApiController::class, 'assignments'])->middleware(['abilities:driver-read'])->name('assignments');
        Route::get('/assignments/{report}/hospitals', [MobileDriverApiController::class, 'hospitals'])->middleware('abilities:driver-read')->name('assignments.hospitals');
        Route::get('/history', [MobileDriverApiController::class, 'history'])->middleware(['abilities:driver-read'])->name('history');
        Route::patch('/assignments/{report}/status', [MobileDriverApiController::class, 'updateStatus'])->middleware(['abilities:driver-write'])->name('assignments.status');
        Route::patch('/availability', [MobileDriverApiController::class, 'updateAvailability'])->middleware(['abilities:driver-write'])->name('availability');
        Route::put('/profile', [MobileDriverApiController::class, 'updateProfile'])->middleware(['abilities:driver-write'])->name('profile.update');
        Route::get('/incident-types', [MobileDriverApiController::class, 'incidentTypes'])->middleware(['abilities:driver-read'])->name('incident-types');
        Route::post('/reports', [MobileDriverApiController::class, 'storeReport'])->middleware(['abilities:driver-write', 'mobile.idempotent'])->name('reports.store');
        Route::get('/teams', [ResponseTeamController::class, 'getTeams'])->middleware(['abilities:driver-read'])->name('teams');
        Route::patch('/teams/{team}/role', [MobileDriverApiController::class, 'changeTeamRole'])->middleware('abilities:driver-write')->name('teams.role');
        Route::patch('/teams/{team}/vehicle', [MobileDriverApiController::class, 'switchTeamVehicle'])->middleware('abilities:driver-write')->name('teams.vehicle');
        Route::get('/maintenance-reports', [MobileDriverApiController::class, 'maintenanceReports'])->middleware('abilities:driver-read')->name('maintenance-reports.index');
        Route::post('/maintenance-reports', [MobileDriverApiController::class, 'storeMaintenanceReport'])->middleware(['abilities:driver-write', 'mobile.idempotent'])->name('maintenance-reports.store');
        Route::get('/reports', [ReportController::class, 'getReports'])->middleware(['abilities:driver-read'])->name('reports');
    });

Route::middleware(['auth:sanctum', 'mobile.session', 'role:resident'])
    ->prefix('resident')
    ->name('api.resident.')
    ->group(function () {
        Route::get('/reports/{report}', [MobileRecordsController::class, 'report'])->middleware('abilities:resident-read');
        Route::put('/reports/{report}', [MobileRecordsController::class, 'updateReport'])->middleware('abilities:resident-write');
        Route::patch('/reports/{report}/withdraw', [MobileRecordsController::class, 'withdraw'])->middleware('abilities:resident-write');
        Route::get('/schedules/{schedule}', [MobileRecordsController::class, 'schedule'])->middleware('abilities:resident-read');
        Route::put('/schedules/{schedule}', [MobileRecordsController::class, 'updateSchedule'])->middleware('abilities:resident-write');
        Route::get('/bootstrap', [MobileResidentApiController::class, 'bootstrap'])->middleware(['abilities:resident-read'])->name('bootstrap');
        Route::get('/reports', [MobileResidentApiController::class, 'reports'])->middleware('abilities:resident-read')->name('reports.index');
        Route::get('/schedules', [MobileResidentApiController::class, 'schedules'])->middleware('abilities:resident-read')->name('schedules.index');
        Route::get('/notifications', [MobileResidentApiController::class, 'notifications'])->middleware('abilities:resident-read')->name('notifications.index');
        Route::post('/reports', [MobileResidentApiController::class, 'storeReport'])->middleware(['abilities:resident-write', 'mobile.idempotent'])->name('reports.store');
        Route::post('/schedules', [MobileResidentApiController::class, 'storeSchedule'])->middleware(['abilities:resident-write', 'mobile.idempotent'])->name('schedules.store');
        Route::patch('/schedules/{schedule}/cancel', [MobileResidentApiController::class, 'cancelSchedule'])->middleware(['abilities:resident-write'])->name('schedules.cancel');
        Route::patch('/notifications/read', [MobileResidentApiController::class, 'markAllRead'])->middleware(['abilities:resident-write'])->name('notifications.read-all');
        Route::patch('/notifications/{notification}/read', [MobileResidentApiController::class, 'markRead'])->middleware(['abilities:resident-write'])->name('notifications.read');
    });

Route::middleware(['auth:sanctum', 'mobile.session', 'role:driver/responder', 'abilities:driver-write'])->prefix('driver-responder/tracking')->group(function (): void {
    Route::post('sessions', [MobileLocationController::class, 'start'])->middleware('throttle:10,1');
    Route::post('sessions/{session}/ping', [MobileLocationController::class, 'ping'])->middleware('throttle:30,1');
    Route::delete('sessions/{session}', [MobileLocationController::class, 'stop']);
});
Route::middleware(['auth:sanctum', 'mobile.session', 'role:admin,dispatcher', 'abilities:staff-read'])->prefix('operations')->group(function (): void {
    Route::get('dashboard', [MobileOperationsController::class, 'dashboard']);
    Route::get('reports', [MobileOperationsController::class, 'reports']);
    Route::get('reports/{report}', [MobileOperationsController::class, 'report']);
    Route::get('schedules', [MobileOperationsController::class, 'schedules']);
    Route::get('teams', [MobileFleetController::class, 'teams']);
    Route::get('teams/{team}', [MobileFleetController::class, 'team']);
    Route::get('drivers', [MobileFleetController::class, 'drivers']);
    Route::get('incident-types', [MobileCatalogController::class, 'types']);
    Route::get('vehicles', [MobileFleetController::class, 'vehicles']);
    Route::get('vehicles/{vehicle}', [MobileFleetController::class, 'vehicle']);
    Route::get('maintenance-reports', [MobileFleetController::class, 'maintenanceReports']);
    Route::get('maintenance-reports/{maintenanceReport}', [MobileFleetController::class, 'maintenanceReport']);
    Route::middleware('abilities:staff-write')->group(function (): void {
        Route::patch('reports/{report}', [MobileOperationsController::class, 'updateReport']);
        Route::post('reports/{report}/assign', [MobileOperationsController::class, 'assign']);
        Route::patch('schedules/{schedule}', [MobileOperationsController::class, 'reviewSchedule']);
        Route::post('teams', [MobileFleetController::class, 'saveTeam']);
        Route::put('teams/{id}', [MobileFleetController::class, 'saveTeam']);
        Route::post('teams/{team}/members', [MobileFleetController::class, 'saveMember']);
        Route::put('teams/{team}/members/{id}', [MobileFleetController::class, 'saveMember']);
        Route::post('teams/{team}/shifts', [MobileFleetController::class, 'saveShift']);
        Route::put('teams/{team}/shifts/{id}', [MobileFleetController::class, 'saveShift']);
        Route::post('vehicles', [MobileFleetController::class, 'saveVehicle']);
        Route::put('vehicles/{id}', [MobileFleetController::class, 'saveVehicle']);
        Route::patch('maintenance-reports/{maintenanceReport}/review', [MobileFleetController::class, 'reviewMaintenanceReport']);
        Route::post('automatic-dispatch/retry', [MobileOperationsController::class, 'retryAutomatic'])->middleware('throttle:5,1');
    });
    Route::middleware(['role:admin', 'abilities:admin-manage'])->group(function (): void {
        Route::get('accounts', [MobileStaffAccountsController::class, 'index']);
        Route::post('accounts', [MobileStaffAccountsController::class, 'save']);
        Route::put('accounts/{account}', [MobileStaffAccountsController::class, 'save']);
        Route::patch('accounts/{account}/review', [MobileStaffAccountsController::class, 'review']);
        Route::get('audit-logs', [MobileOperationsController::class, 'audits']);
        Route::put('automatic-dispatch', [MobileOperationsController::class, 'automatic'])->middleware('throttle:5,1');
        Route::post('incident-types', [MobileCatalogController::class, 'saveType']);
        Route::put('incident-types/{id}', [MobileCatalogController::class, 'saveType']);
        Route::delete('incident-types/{type}', [MobileCatalogController::class, 'deleteType']);
    });
});

Route::middleware(['auth:sanctum', 'mobile.session', 'role:driver/responder', 'abilities:driver-write'])->prefix('driver-responder/location')->group(function (): void {
    Route::post('sessions', [MobileDriverLocationController::class, 'start'])->middleware('throttle:10,1');
    Route::post('sessions/{session}/ping', [MobileDriverLocationController::class, 'ping'])->middleware('throttle:30,1');
    Route::delete('sessions/{session}', [MobileDriverLocationController::class, 'stop']);
});

Route::get('driver-responder/locations', [MobileDriverLocationController::class, 'index'])
    ->middleware(['auth:sanctum', 'mobile.session', 'role:driver/responder', 'abilities:driver-read']);

Route::get('operations/locations', [MobileDriverLocationController::class, 'staffIndex'])
    ->middleware(['auth:sanctum', 'mobile.session', 'role:admin,dispatcher', 'abilities:staff-read']);
