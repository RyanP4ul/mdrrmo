<?php

namespace App\Http\Controllers;

use App\Events\VehicleLocationUpdated;
use App\Models\DispatchTrip;
use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\MaintenanceReport;
use App\Models\ResponseTeam;
use App\Models\Vehicle;
use App\Services\DispatchService;
use App\Services\DriverResponseProgress;
use App\Services\EmergencyReportWorkflow;
use App\Services\TeamRoles;
use App\Services\VehicleMaintenanceService;
use App\Support\MobilePage;
use App\Support\MobileValidation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileDriverApiController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();

        $assignments = $this->activeReports($driver)->get();
        $assignment = $assignments->first();
        $teamRoles = app(TeamRoles::class)->memberships($driver);
        $vehicles = collect($teamRoles)->flatMap(fn (array $role) => $role['vehicles'] ?? [])->unique('id')->values()->all();
        $activeVehicle = collect($teamRoles)->pluck('active_vehicle')->filter()->first();

        return response()->json([
            'unread_count' => DriverNotification::where('driver_id', $driver->id)->whereNull('read_at')->count(),
            'assignments' => $assignments->map(fn ($report) => $this->reportData($report)),
            'profile' => [
                'name' => $driver->full_name,
                'contact' => $driver->contact_number ?? '',
                'vehicle_status' => $activeVehicle['vehicle_status'] ?? 'available',
                'vehicle_id' => $activeVehicle['id'] ?? null,
            ],
            'teams' => $this->teamMembers($driver),
            'team_roles' => $teamRoles,
            'vehicles' => $vehicles,
            'can_dispatch' => $assignments->isNotEmpty() || collect($teamRoles)->contains('role', 'Driver'),
            'assignment' => $assignment
                ? $this->reportData($assignment)
                : null,
            'incident_types' => IncidentType::query()
                ->orderBy('name')
                ->get(['id', 'name', 'priority', 'color']),
            'maintenance_issue_types' => MaintenanceReport::ISSUE_TYPES,
        ]);
    }

    public function assignments(Request $request): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $vehicle = $this->vehicleFor($driver);

        return response()->json([
            'data' => $this->activeReports($driver)
                ->with(['incidentType:id,name,color', 'team.vehicles', 'driver:id,first_name,last_name'])
                ->get()
                ->map(fn (EmergencyReport $report): array => $this->reportData($report, $vehicle)),
        ]);
    }

    public function changeTeamRole(Request $request, ResponseTeam $team, TeamRoles $roles): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'in:Driver,Responder']]);
        $roles->changeOwnRole($team, $request->user(), $data['role']);

        return response()->json([
            'team_roles' => $roles->memberships($request->user()),
            'teams' => $this->teamMembers($request->user()),
        ]);
    }

    public function switchTeamVehicle(Request $request, ResponseTeam $team, TeamRoles $roles): JsonResponse
    {
        $data = $request->validate(['vehicle_id' => ['required', 'integer', 'min:1']]);
        $vehicle = $roles->switchVehicle($team, $request->user(), (int) $data['vehicle_id']);
        $teamRoles = $roles->memberships($request->user());

        return response()->json([
            'vehicle' => $vehicle->toTeamPayload($vehicle->id),
            'team_roles' => $teamRoles,
            'vehicles' => collect($teamRoles)->flatMap(fn (array $role) => $role['vehicles'] ?? [])->unique('id')->values()->all(),
        ]);
    }

    public function maintenanceReports(Request $request, VehicleMaintenanceService $service): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $teamIds = ResponseTeam::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $driver->id)->where('status', 'active'))
            ->pluck('id')
            ->all();

        $query = MaintenanceReport::query()
            ->with(['vehicle', 'team', 'reporter', 'reviewer'])
            ->where(function ($q) use ($driver, $teamIds): void {
                $q->where('reported_by', $driver->id);
                if (! empty($teamIds)) {
                    $q->orWhereIn('response_team_id', $teamIds);
                }
            });

        return response()->json(MobilePage::make(
            $query,
            fn (MaintenanceReport $report): array => $service->formatReport($report),
            $request,
        ));
    }

    public function storeMaintenanceReport(Request $request, VehicleMaintenanceService $service): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'issue_type' => ['required', 'string', Rule::in(MaintenanceReport::ISSUE_TYPES)],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'description' => ['required', 'string', 'max:2000'],
            'recommend_out_of_service' => ['nullable', 'boolean'],
        ]);

        $report = $service->submitReport($driver, $data);

        return response()->json(['report' => $service->formatReport($report)], 201);
    }

    public function hospitals(Request $request, EmergencyReport $report, DispatchService $dispatch): JsonResponse
    {
        abort_unless($report->assigned_driver_id === $request->user()->getKey(), 404);

        return response()->json([
            'report_id' => $report->getKey(),
            'data' => $dispatch->recommendHospitals($report),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $vehicle = $this->vehicleFor($driver);

        return response()->json(MobilePage::make(EmergencyReport::query()
            ->where('assigned_driver_id', $driver->getKey())->whereIn('status', ['resolved', 'invalid'])
            ->with(['incidentType:id,name,color', 'team.vehicles', 'driver:id,first_name,last_name']),
            fn (EmergencyReport $report): array => $this->reportData($report, $vehicle), $request));
    }

    public function updateStatus(Request $request, EmergencyReport $report, EmergencyReportWorkflow $workflow): JsonResponse
    {
        $driver = $request->user();
        abort_unless($report->assigned_driver_id === $driver->getKey(), 404);
        $request->merge(['response_status' => $request->input('response_status', $request->input('vehicle_status'))]);
        $data = $request->validate(['response_status' => ['required', 'in:en-route,on-scene,available']]);
        $updated = app(DriverResponseProgress::class)->advance($driver, $report, $data['response_status']);

        return response()->json(['assignment' => $updated->status === 'resolved' ? null : $this->reportData($updated)]);
    }

    public function updateAvailability(Request $request): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $request->validate(['vehicle_id' => ['nullable', 'integer', 'min:1']]);
        $status = $request->validate([
            'vehicle_status' => ['required', 'in:available,offline'],
        ])['vehicle_status'];

        $vehicle = DB::transaction(function () use ($driver, $status, $request): Vehicle {
            DriverResponderUser::query()->whereKey($driver->getKey())->lockForUpdate()->firstOrFail();
            abort_if($this->activeReports($driver)->exists(), 422, 'Complete your active assignment before changing availability.');
            $selected = $this->vehicleFor($driver, $request->integer('vehicle_id') ?: null, true);
            abort_unless($selected, 404, 'No vehicle is assigned to your team.');
            $vehicle = Vehicle::query()->whereKey($selected->getKey())->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $vehicle);
            abort_if($vehicle->assigned_report_id !== null || ! in_array($vehicle->status, ['available', 'offline'], true), 422, 'This vehicle is assigned to an active incident or under maintenance.');
            $vehicle->update(['status' => $status]);

            return $vehicle;
        });
        event(new VehicleLocationUpdated($vehicle->refresh()));

        return response()->json(['vehicle_status' => $vehicle->status]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        MobileValidation::normalizePhone($request, 'contact');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'contact' => MobileValidation::phoneRules(), 'vehicle_id' => ['prohibited'], 'vehicle_type' => ['prohibited'], 'vehicle_number' => ['prohibited']]);
        $parts = MobileValidation::nameParts($data['name']);
        $driver = $request->user();
        $driver->update(['first_name' => $parts[0], 'last_name' => $parts[1] ?? '', 'contact_number' => $data['contact']]);

        return response()->json(['profile' => ['name' => $driver->fresh()->full_name, 'contact' => $driver->contact_number, 'vehicle_status' => 'available']]);
    }

    public function incidentTypes(): JsonResponse
    {
        return response()->json(
            IncidentType::query()->orderBy('name')->get(['id', 'name', 'priority', 'color']),
        );
    }

    public function storeReport(Request $request): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        MobileValidation::normalizePhone($request, 'contact_number');
        $data = $request->validate([
            'incident_type_id' => ['required', 'integer', 'exists:incident_types,id'],
            'location' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'priority' => ['required', 'in:low,medium,high,critical'],
            'patient_name' => ['required', 'string', 'max:255'],
            'patient_age' => ['required', 'integer', 'between:0,120'],
            'symptoms' => ['required', 'string', 'max:2000'],
            'contact_person' => ['required', 'string', 'max:255'],
            'contact_number' => MobileValidation::phoneRules(),
        ]);

        $incidentType = IncidentType::query()->findOrFail($data['incident_type_id']);
        $report = EmergencyReport::query()->create([
            'reference' => 'RPT-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
            'incident_type_id' => $incidentType->getKey(),
            'location' => $data['location'],
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'description' => $data['symptoms'],
            'status' => 'pending',
            'priority' => $data['priority'],
            'source' => 'mobile-app',
            'reporter_type' => 'victim',
            'reported_by' => $driver->getKey(),
            'reported_by_type' => $driver->getMorphClass(),
            'reporter_name' => $driver->full_name,
            'reporter_contact' => $driver->contact_number,
            'reported_at' => now(),
            'victim_info' => [
                'name' => $data['patient_name'],
                'age' => $data['patient_age'],
                'symptoms' => $data['symptoms'],
                'contact_person' => $data['contact_person'],
                'contact_number' => $data['contact_number'],
            ],
        ]);
        DriverNotification::receipt($driver->id, $report, 'submitted', 'Report received');
        $report->load('incidentType:id,name,color');

        return response()->json(['report' => $this->reportData($report)], 201);
    }

    /**
     * @return Builder<EmergencyReport>
     */
    private function activeReports(DriverResponderUser $driver): Builder
    {
        return EmergencyReport::query()
            ->where('assigned_driver_id', $driver->getKey())
            ->where(fn ($query) => $query->whereDoesntHave('team')->orWhereHas('team', fn ($team) => $team
                ->where('member_roles_enabled', false)->orWhere(fn ($managed) => $managed->where('status', 'active')
                ->whereHas('members', fn ($member) => $member->where('user_id', $driver->id)->where('status', 'active')->where('role', 'Driver')))))
            ->whereIn('status', ['dispatched', 'acknowledged'])
            ->with(['incidentType:id,name,color', 'team.vehicles', 'driver:id,first_name,last_name'])
            ->latest('reported_at');
    }

    private function editableVehicles(DriverResponderUser $driver)
    {
        return Vehicle::query()->whereHas('team.members', fn ($query) => $query->where('user_id', $driver->getKey()))
            ->with(['team', 'report'])->orderBy('id')->get()->filter(fn (Vehicle $vehicle): bool => Gate::forUser($driver)->allows('update', $vehicle))->values();
    }

    private function vehicleFor(DriverResponderUser $driver, ?int $id = null, bool $requireChoice = false): ?Vehicle
    {
        $vehicles = $this->editableVehicles($driver);
        if ($id !== null) {
            $vehicle = $vehicles->firstWhere('id', $id);
            abort_unless($vehicle, 404, 'This vehicle is not available to your account.');

            return $vehicle;
        }
        $assigned = $vehicles->first(fn (Vehicle $vehicle): bool => $vehicle->assigned_report_id !== null);
        if ($assigned) {
            return $assigned;
        }
        if ($requireChoice && $vehicles->count() > 1) {
            throw ValidationException::withMessages(['vehicle_id' => 'Choose the vehicle you are using.']);
        }

        return $vehicles->count() === 1 ? $vehicles->first() : null;
    }

    /**
     * @return array<int, array{name: string, role: string, online: bool}>
     */
    private function teamMembers(DriverResponderUser $driver): array
    {
        return ResponseTeam::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $driver->getKey()))
            ->with('members.user')
            ->get()
            ->flatMap(fn (ResponseTeam $team) => $team->members->map(fn ($member): array => [
                'name' => $member->user?->full_name ?? $member->name,
                'contact' => $member->user?->contact_number,
                'last_seen_at' => $member->user?->last_seen_at,
                'role' => $member->role ?? 'Team member',
                'online' => $member->user?->status === 'active' && $member->user?->last_seen_at && Carbon::parse($member->user->last_seen_at)->greaterThan(now()->subSeconds(90)),
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportData(EmergencyReport $report, ?Vehicle $vehicle = null): array
    {
        $report->loadMissing(['incidentType', 'team.vehicles', 'team.members.user', 'driver', 'form']);
        $teamVehicle = $report->team?->vehicles->firstWhere('assigned_report_id', $report->getKey())
            ?? $report->team?->resolveActiveVehicle();
        $trip = DispatchTrip::where('emergency_report_id', $report->id)->first();
        // Match DriverResponseProgress for assignments created before trip events.
        // An active team vehicle may be working on a different report.
        $responseStatus = $trip?->on_scene_at ? 'on-scene' : ($trip?->started_at ? 'en-route'
            : (Vehicle::where('assigned_report_id', $report->id)->value('status') ?? 'available'));
        $patientName = trim((string) (($report->victim_info['name'] ?? null) ?: $report->reporter_name ?: ''));
        $responders = $report->team?->members
            ? $report->team->members
                ->where('status', 'active')
                ->filter(fn ($m) => ($m->role ?? '') !== 'Driver' && $m->user_id !== $report->assigned_driver_id)
                ->map(fn ($m) => trim((string) ($m->user?->full_name ?? $m->name)))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];
        $authorizedPassenger = trim((string) ($report->form?->driver_section['authorizedPassenger'] ?? $report->form?->driver_section['passengerName'] ?? ''))
            ?: collect([$patientName, ...$responders])->filter()->unique()->implode(', ');
        $driverSection = $report->form?->driver_section ?? [];
        $hasDriverForm = ! empty(array_filter($driverSection, fn ($v) => $v !== null && $v !== ''));

        return [
            'id' => $report->getKey(),
            'reference' => $report->reference,
            'type' => $report->incidentType?->name ?? 'Unknown',
            'priority' => $report->priority,
            'location' => $report->location,
            'lat' => $report->lat,
            'lng' => $report->lng,
            'status' => $report->status,
            'reported_at' => $report->reported_at?->toIso8601String(),
            'resolved_at' => $report->resolved_at?->toIso8601String(),
            'driver' => $report->driver?->full_name,
            'patient_name' => $patientName,
            'authorized_passenger' => $authorizedPassenger,
            'has_driver_form' => $hasDriverForm,
            'trip' => $trip,
            'response_status' => $responseStatus,
            'vehicle_id' => $teamVehicle?->id,
            'vehicle' => $teamVehicle?->plate_number ?? $trip?->vehicle_number ?? $vehicle?->plate_number,
            'vehicle_status' => $teamVehicle?->status ?? $vehicle?->status,
        ];
    }
}
