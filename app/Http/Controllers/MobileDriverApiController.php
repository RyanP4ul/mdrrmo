<?php

namespace App\Http\Controllers;

use App\Events\VehicleLocationUpdated;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResponseTeam;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileDriverApiController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $vehicle = $this->vehicleFor($driver);

        return response()->json([
            'profile' => [
                'name' => $driver->full_name,
                'contact' => $driver->contact_number ?? '',
                'vehicle_type' => $vehicle?->vehicle_type,
                'vehicle_number' => $vehicle?->plate_number,
                'vehicle_status' => $vehicle?->status ?? 'offline',
            ],
            'teams' => $this->teamMembers($driver),
            'assignment' => $this->activeReports($driver)->first()
                ? $this->reportData($this->activeReports($driver)->first(), $vehicle)
                : null,
            'incident_types' => IncidentType::query()
                ->orderBy('name')
                ->get(['id', 'name', 'priority', 'color']),
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

    public function history(Request $request): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $vehicle = $this->vehicleFor($driver);

        return response()->json([
            'data' => EmergencyReport::query()
                ->where('assigned_driver_id', $driver->getKey())
                ->whereIn('status', ['resolved', 'invalid'])
                ->with(['incidentType:id,name,color', 'team.vehicles', 'driver:id,first_name,last_name'])
                ->latest('reported_at')
                ->limit(100)
                ->get()
                ->map(fn (EmergencyReport $report): array => $this->reportData($report, $vehicle)),
        ]);
    }

    public function updateStatus(Request $request, EmergencyReport $report): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        abort_unless($report->assigned_driver_id === $driver->getKey(), 404);

        $data = $request->validate([
            'vehicle_status' => ['required', 'in:en-route,on-scene,available'],
        ]);
        $vehicle = Vehicle::query()
            ->where('response_team_id', $report->response_team_id)
            ->firstOrFail();

        $nextStatus = $data['vehicle_status'];
        $validTransition = match ($nextStatus) {
            'en-route' => in_array($vehicle->status, ['available', 'offline'], true)
                && in_array($report->status, ['acknowledged', 'dispatched'], true),
            'on-scene' => $vehicle->status === 'en-route'
                && in_array($report->status, ['acknowledged', 'dispatched'], true),
            'available' => $vehicle->status === 'on-scene'
                && in_array($report->status, ['acknowledged', 'dispatched'], true),
        };
        abort_unless($validTransition, 422, 'The assignment cannot move to that status yet.');

        DB::transaction(function () use ($nextStatus, $report, $vehicle): void {
            $vehicle->update([
                'status' => $nextStatus,
                'assigned_report_id' => $nextStatus === 'available' ? null : $report->getKey(),
            ]);

            if ($nextStatus === 'available') {
                $report->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        });

        event(new VehicleLocationUpdated($vehicle->refresh()));

        return response()->json([
            'assignment' => $nextStatus === 'available'
                ? null
                : $this->reportData($report->refresh()->load(['incidentType', 'team.vehicles', 'driver']), $vehicle),
        ]);
    }

    public function updateAvailability(Request $request): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $status = $request->validate([
            'vehicle_status' => ['required', 'in:available,offline'],
        ])['vehicle_status'];

        abort_if($this->activeReports($driver)->exists(), 422, 'Complete your active assignment before changing availability.');
        $vehicle = $this->vehicleFor($driver);
        abort_unless($vehicle, 404, 'No vehicle is assigned to your team.');

        $vehicle->update(['status' => $status, 'assigned_report_id' => null]);
        event(new VehicleLocationUpdated($vehicle->refresh()));

        return response()->json(['vehicle_status' => $vehicle->status]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var DriverResponderUser $driver */
        $driver = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact' => ['required', 'string', 'max:20'],
            'vehicle_type' => ['required', 'in:ambulance,fire-truck,rescue-van,police-car,utility-truck'],
            'vehicle_number' => ['required', 'string', 'max:255'],
        ]);

        $nameParts = preg_split('/\s+/', trim($data['name']), 2);
        if ($nameParts === false || $nameParts[0] === '') {
            throw ValidationException::withMessages(['name' => 'Enter a valid name.']);
        }

        $vehicle = $this->vehicleFor($driver);
        abort_unless($vehicle, 404, 'No vehicle is assigned to your team.');

        $driver->update([
            'first_name' => $nameParts[0],
            'last_name' => $nameParts[1] ?? '',
            'contact_number' => $data['contact'],
        ]);

        $vehicle->update([
            'vehicle_type' => $data['vehicle_type'],
            'plate_number' => $data['vehicle_number'],
        ]);

        return response()->json([
            'profile' => [
                'name' => $driver->fresh()->full_name,
                'contact' => $driver->contact_number,
                'vehicle_type' => $vehicle?->vehicle_type,
                'vehicle_number' => $vehicle?->plate_number,
                'vehicle_status' => $vehicle?->status ?? 'offline',
            ],
        ]);
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
            'contact_number' => ['required', 'string', 'max:20'],
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
        $report->load('incidentType:id,name,color');

        return response()->json(['report' => $this->reportData($report)], 201);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<EmergencyReport>
     */
    private function activeReports(DriverResponderUser $driver)
    {
        return EmergencyReport::query()
            ->where('assigned_driver_id', $driver->getKey())
            ->whereIn('status', ['dispatched', 'acknowledged'])
            ->with(['incidentType:id,name,color', 'team.vehicles', 'driver:id,first_name,last_name'])
            ->latest('reported_at');
    }

    private function vehicleFor(DriverResponderUser $driver): ?Vehicle
    {
        return Vehicle::query()
            ->whereHas('team.members', fn ($query) => $query->where('user_id', $driver->getKey()))
            ->with('team.members')
            ->first();
    }

    /**
     * @return array<int, array{name: string, role: string, online: bool}>
     */
    private function teamMembers(DriverResponderUser $driver): array
    {
        return ResponseTeam::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $driver->getKey()))
            ->with('members')
            ->get()
            ->flatMap(fn (ResponseTeam $team) => $team->members->map(fn ($member): array => [
                'name' => $member->name,
                'role' => $member->role ?? 'Team member',
                'online' => $team->status === 'active' && $member->status === 'active',
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportData(EmergencyReport $report, ?Vehicle $vehicle = null): array
    {
        $report->loadMissing(['incidentType', 'team.vehicles', 'driver']);
        $teamVehicle = $report->team?->vehicles->firstWhere('assigned_report_id', $report->getKey());

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
            'vehicle' => $teamVehicle?->plate_number ?? $vehicle?->plate_number,
            'vehicle_status' => $teamVehicle?->status ?? $vehicle?->status,
        ];
    }
}
