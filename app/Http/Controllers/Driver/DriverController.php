<?php

namespace App\Http\Controllers\Driver;

use App\Events\DriverLocationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePatientReportRequest;
use App\Http\Requests\StoreDriverLocationPingRequest;
use App\Models\DriverLocation;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\MaintenanceReport;
use App\Models\ReportForm;
use App\Models\ResponseTeam;
use App\Services\DriverResponseProgress;
use App\Services\EmergencyReportWorkflow;
use App\Services\TeamRoles;
use App\Services\VehicleMaintenanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DriverController extends Controller
{
    private function mine(Request $r)
    {
        $userId = $r->user()->id;

        return EmergencyReport::with(['incidentType:id,name,color', 'team:id,team_name'])
            ->where(function ($query) use ($userId): void {
                $query->where('assigned_driver_id', $userId)
                    ->orWhereHas('team', fn ($team) => $team
                        ->where('status', 'active')
                        ->whereHas('members', fn ($m) => $m->where('user_id', $userId)->where('status', 'active')));
            });
    }

    public function dashboard(Request $request, TeamRoles $roles)
    {
        /** @var DriverResponderUser $user */
        $user = $request->user();
        $teamRoles = $roles->memberships($user);
        $isDriver = empty($teamRoles) || collect($teamRoles)->contains('role', 'Driver');
        $active = $this->mine($request)
            ->whereIn('status', ['dispatched', 'acknowledged'])
            ->with('trip')
            ->latest('reported_at')
            ->get()
            ->map(function (EmergencyReport $report) use ($user): array {
                return array_merge($report->toArray(), [
                    'can_advance' => Gate::forUser($user)->allows('advanceAsDriver', $report),
                    'can_edit_trip' => Gate::forUser($user)->allows('editTripAsDriver', $report),
                    'can_fill_patient_form' => Gate::forUser($user)->allows('updateAsDriver', $report),
                ]);
            });

        return Inertia::render('Driver/Dashboard', [
            'active' => $active,
            'doneToday' => $this->mine($request)->where('status', 'resolved')->whereDate('resolved_at', today())->count(),
            'teamRoles' => $teamRoles,
            'isDriver' => $isDriver,
        ]);
    }

    public function assignments(Request $request)
    {
        return Inertia::render('Driver/Assignments', [
            'reports' => $this->mine($request)->latest('reported_at')->paginate(8)->withQueryString(),
        ]);
    }

    /** Driver advances an assignment: dispatched -> (en-route/on-scene) -> resolved */
    public function updateStatus(Request $request, EmergencyReport $report, EmergencyReportWorkflow $workflow)
    {
        $request->merge(['response_status' => $request->input('response_status', $request->input('vehicle_status'))]);
        $data = $request->validate(['response_status' => ['required', 'in:en-route,on-scene,returning,available']]);
        app(DriverResponseProgress::class)->advance($request->user(), $report, $data['response_status']);

        return back()->with('success', 'Response progress updated.');
    }

    public function reports(Request $request, TeamRoles $roles)
    {
        /** @var DriverResponderUser $user */
        $user = $request->user();
        $teamRoles = $roles->memberships($user);
        $isDriver = empty($teamRoles) || collect($teamRoles)->contains('role', 'Driver');

        return Inertia::render('Driver/Reports', [
            'reports' => $this->mine($request)
                ->whereIn('status', ['dispatched', 'resolved'])
                ->with(['form', 'trip', 'driver:id,first_name,last_name', 'team.members.user', 'team.vehicles'])
                ->latest('reported_at')
                ->paginate(6)
                ->withQueryString(),
            'isDriver' => $isDriver,
            'teamRoles' => $teamRoles,
        ]);
    }

    public function saveForm(SavePatientReportRequest $request, EmergencyReport $report)
    {
        Gate::authorize('updateAsDriver', $report);
        $validated = $request->validated();
        $existing = $report->form;
        $driverSection = array_key_exists('driver_section', $validated) && is_array($validated['driver_section'])
            ? array_merge($existing?->driver_section ?? [], $validated['driver_section'])
            : ($existing?->driver_section ?? []);
        $emergencySection = array_key_exists('emergency_section', $validated) && is_array($validated['emergency_section'])
            ? array_merge($existing?->emergency_section ?? [], $validated['emergency_section'])
            : ($existing?->emergency_section ?? []);
        ReportForm::updateOrCreate(
            ['emergency_report_id' => $report->id],
            [
                'driver_section' => $driverSection,
                'emergency_section' => $emergencySection,
            ],
        );

        return back()->with('success', 'Report saved.');
    }

    public function team(Request $request, TeamRoles $roles, VehicleMaintenanceService $maintenanceService)
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $teamRoles = $roles->memberships($driver);
        $teamIds = collect($teamRoles)->pluck('team_id')->all();

        $members = ResponseTeam::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $driver->getKey()))
            ->with('members.user')
            ->get()
            ->flatMap(fn (ResponseTeam $team) => $team->members->map(fn ($member): array => [
                'id' => $member->id,
                'team_id' => $team->id,
                'team_name' => $team->team_name,
                'name' => $member->user?->full_name ?? $member->name,
                'contact' => $member->user?->contact_number,
                'role' => $member->role ?? 'Team member',
                'status' => $member->status,
                'online' => $member->user?->status === 'active'
                    && $member->user?->last_seen_at
                    && Carbon::parse($member->user->last_seen_at)->greaterThan(now()->subSeconds(90)),
            ]))
            ->values()
            ->all();

        $maintenanceReports = MaintenanceReport::query()
            ->where(function ($query) use ($driver, $teamIds): void {
                $query->where('reported_by', $driver->id);
                if (! empty($teamIds)) {
                    $query->orWhereIn('response_team_id', $teamIds);
                }
            })
            ->with(['vehicle', 'team', 'reporter', 'reviewer'])
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (MaintenanceReport $report): array => $maintenanceService->formatReport($report))
            ->values()
            ->all();

        return Inertia::render('Driver/Team', [
            'teamRoles' => $teamRoles,
            'members' => $members,
            'maintenanceReports' => $maintenanceReports,
            'issueTypes' => MaintenanceReport::ISSUE_TYPES,
        ]);
    }

    public function changeTeamRole(Request $request, ResponseTeam $team, TeamRoles $roles)
    {
        $data = $request->validate(['role' => ['required', 'in:Driver,Responder']]);
        $roles->changeOwnRole($team, $request->user(), $data['role']);

        return back()->with('success', "Role updated to {$data['role']}.");
    }

    public function switchVehicle(Request $request, ResponseTeam $team, TeamRoles $roles)
    {
        $data = $request->validate(['vehicle_id' => ['required', 'integer', 'exists:vehicles,id']]);
        $vehicle = $roles->switchVehicle($team, $request->user(), (int) $data['vehicle_id']);

        return back()->with('success', "Switched active ambulance to {$vehicle->plate_number}.");
    }

    public function storeMaintenanceReport(Request $request, VehicleMaintenanceService $maintenanceService)
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'issue_type' => ['required', 'string', Rule::in(MaintenanceReport::ISSUE_TYPES)],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'description' => ['required', 'string', 'max:2000'],
            'recommend_out_of_service' => ['nullable', 'boolean'],
        ]);

        $maintenanceService->submitReport($request->user(), $data);

        return back()->with('success', 'Maintenance report submitted for Admin review.');
    }

    public function tracking(Request $request)
    {
        return Inertia::render('Driver/Tracking', [
            'location' => DriverLocation::find($request->user()->id)?->mapData(),
        ]);
    }

    /** Called every few seconds by the driver's phone (navigator.geolocation.watchPosition). */
    public function ping(StoreDriverLocationPingRequest $request)
    {
        $d = $request->validated();

        $location = DriverLocation::updateOrCreate(['driver_id' => $request->user()->id], [
            'session_id' => (string) Str::uuid(), 'lat' => $d['lat'], 'lng' => $d['lng'],
            'accuracy' => $d['accuracy'], 'speed' => (int) round(($d['speed'] ?? 0) * 3.6),
            'heading' => (int) round($d['heading'] ?? 0), 'observed_at' => now(), 'expires_at' => now()->addMinutes(3),
        ]);
        DriverLocationUpdated::dispatch($location);

        return response()->noContent();
    }
}
