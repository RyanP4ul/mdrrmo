<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\Vehicle;
use App\Services\DispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private DispatchService $dispatch) {}

    public function index()
    {
        $team = $this->dispatch->autoAssignTeam();
        $activeReportsByTeam = EmergencyReport::query()
            ->whereIn('status', ['acknowledged', 'dispatched'])
            ->whereNotNull('response_team_id')
            ->orderByDesc('id')
            ->get(['response_team_id', 'reference'])
            ->keyBy('response_team_id');
        $activeScheduleTeamIds = ServiceSchedule::query()
            ->whereIn('status', ['en-route', 'on-scene'])
            ->whereNotNull('response_team_id')
            ->pluck('response_team_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
        $busyTeamIds = $this->dispatch->busyTeamIds();
        $inUseVehicleIds = $this->dispatch->inUseVehicleIds();

        $teams = ResponseTeam::query()
            ->where('status', 'active')
            ->with([
                'members' => fn ($q) => $q->where('status', 'active')->with('user:id,status'),
                'vehicles',
                'activeVehicle',
            ])
            ->orderBy('team_name')
            ->get()
            ->map(function (ResponseTeam $t) use ($busyTeamIds, $activeReportsByTeam, $activeScheduleTeamIds, $inUseVehicleIds): array {
                $onShift = $this->dispatch->onShiftMembers($t);
                $members = ($onShift->isNotEmpty() ? $onShift : $t->members)
                    ->map(fn ($m): array => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'role' => $m->role,
                    ])->values()->all();
                $vehicle = $t->resolveActiveVehicle();
                $vehicleLabel = $vehicle?->displayName();
                $activeRef = $activeReportsByTeam->get($t->id)?->reference
                    ?? (in_array($t->id, $activeScheduleTeamIds, true) ? 'Scheduled service' : null);

                return [
                    'id' => $t->id,
                    'name' => $t->team_name,
                    'shift_team' => $t->shift_team,
                    'is_available' => ! in_array($t->id, $busyTeamIds, true),
                    'active_report_reference' => $activeRef,
                    'vehicle_name' => $vehicleLabel,
                    'default_vehicle' => $vehicle ? [
                        'id' => $vehicle->id,
                        'name' => $vehicle->displayName(),
                        'plate_number' => $vehicle->plate_number,
                        'vehicle_type' => $vehicle->vehicle_type,
                        'vehicle_type_label' => Vehicle::typeLabel($vehicle->vehicle_type),
                        'status' => $vehicle->status,
                        'is_available' => $vehicle->status === 'available'
                            && $vehicle->assigned_report_id === null
                            && ! in_array($vehicle->id, $inUseVehicleIds, true),
                    ] : null,
                    'members' => $members,
                ];
            })
            ->values();

        $vehicles = Vehicle::query()
            ->with('team:id,team_name')
            ->orderByRaw("CASE WHEN status = 'available' AND assigned_report_id IS NULL THEN 0 ELSE 1 END")
            ->orderBy('vehicle_type')
            ->orderBy('name')
            ->orderBy('plate_number')
            ->get()
            ->map(fn (Vehicle $v): array => [
                'id' => $v->id,
                'name' => $v->displayName(),
                'plate_number' => $v->plate_number,
                'vehicle_type' => $v->vehicle_type,
                'vehicle_type_label' => Vehicle::typeLabel($v->vehicle_type),
                'status' => $v->status,
                'response_team_id' => $v->response_team_id,
                'team_name' => $v->team?->team_name,
                'assigned_report_id' => $v->assigned_report_id,
                'is_available' => $v->status === 'available'
                    && $v->assigned_report_id === null
                    && ! in_array($v->id, $inUseVehicleIds, true),
            ])
            ->values();

        $autoVehicle = $team?->resolveActiveVehicle();
        $autoVehicleLabel = $autoVehicle?->displayName();

        return Inertia::render('Dispatcher/Dashboard', [
            'incoming' => EmergencyReport::with([
                'incidentType:id,name,color',
                'team:id,team_name',
                'parentReport:id,reference,status,response_team_id',
                'requestedByTeam:id,team_name',
                'assignedVehicle:id,name,plate_number,vehicle_type,status,assigned_report_id',
                'activeDispatchAssignment.vehicle:id,name,plate_number,vehicle_type,status',
            ])->active()
                ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->orderByRaw("status = 'pending' desc")->latest('reported_at')->orderByDesc('id')->get()
                ->map(function (EmergencyReport $report): array {
                    $assignedVehicle = $report->assignedVehicle ?? $report->activeDispatchAssignment?->vehicle;
                    $isBackup = $report->isBackupRequest();

                    return array_merge($report->toArray(), [
                        'photo_url' => $report->photo_path ? route('dispatcher.reports.photo', $report) : null,
                        'is_backup' => $isBackup,
                        'requested_vehicle_type' => $report->requested_vehicle_type,
                        'requested_vehicle_label' => Vehicle::typeLabel($report->requested_vehicle_type),
                        'excluded_vehicle_ids' => $isBackup ? $this->dispatch->backupExcludedVehicleIds($report) : [],
                        'parent_report' => $report->parentReport ? [
                            'id' => $report->parentReport->id,
                            'reference' => $report->parentReport->reference,
                            'status' => $report->parentReport->status,
                        ] : null,
                        'requested_by_team' => $report->requestedByTeam ? [
                            'id' => $report->requestedByTeam->id,
                            'team_name' => $report->requestedByTeam->team_name,
                        ] : null,
                        'assigned_vehicle' => $assignedVehicle ? [
                            'id' => $assignedVehicle->id,
                            'name' => $assignedVehicle->displayName(),
                            'plate_number' => $assignedVehicle->plate_number,
                            'vehicle_type' => $assignedVehicle->vehicle_type,
                            'vehicle_type_label' => Vehicle::typeLabel($assignedVehicle->vehicle_type),
                            'status' => $assignedVehicle->status,
                        ] : null,
                    ]);
                }),
            'counts' => EmergencyReport::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'types' => IncidentType::orderBy('name')->get(['id', 'name', 'priority']),
            'autoTeam' => $team ? [
                'id' => $team->id,
                'name' => $team->team_name,
                'is_available' => ! in_array($team->id, $busyTeamIds, true),
                'active_report_reference' => $activeReportsByTeam->get($team->id)?->reference,
                'vehicle_name' => $autoVehicleLabel,
                'default_vehicle' => $autoVehicle ? [
                    'id' => $autoVehicle->id,
                    'name' => $autoVehicle->displayName(),
                    'plate_number' => $autoVehicle->plate_number,
                    'vehicle_type' => $autoVehicle->vehicle_type,
                    'vehicle_type_label' => Vehicle::typeLabel($autoVehicle->vehicle_type),
                    'status' => $autoVehicle->status,
                    'is_available' => $autoVehicle->status === 'available'
                        && $autoVehicle->assigned_report_id === null
                        && ! in_array($autoVehicle->id, $inUseVehicleIds, true),
                ] : null,
                'members' => $this->dispatch->onShiftMembers($team)->map->only('id', 'name', 'role')->values(),
            ] : null,
            'teams' => $teams,
            'vehicles' => $vehicles,
        ]);
    }

    /** Create (phone call) or dispatch (existing report). */
    public function store(Request $request)
    {
        if ($request->filled('report_id')) {
            $data = $request->validate([
                'report_id' => ['required', 'integer', 'exists:emergency_reports,id'],
                'team_id' => ['nullable', 'integer', 'exists:response_teams,id'],
                'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            ]);

            $report = EmergencyReport::findOrFail($data['report_id']);
            Gate::authorize('dispatch', $report);

            $selectedTeam = ! empty($data['team_id']) ? ResponseTeam::find($data['team_id']) : null;
            $selectedVehicle = ! empty($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : null;
            $report = $this->dispatch->dispatch($report, $selectedTeam, null, false, $selectedVehicle);

            $vehicleLabel = $selectedVehicle ? " with {$selectedVehicle->displayName()}" : '';

            return redirect()->route('dispatcher.reports.show', $report)->with('success', "Dispatched {$report->team->team_name}{$vehicleLabel}.");
        }

        $data = $request->validate([
            'incident_type_id' => ['required', 'exists:incident_types,id'],
            'team_id' => ['nullable', 'integer', 'exists:response_teams,id'],
            'reporter_type' => ['required', 'in:victim,witness'],
            'reporter_name' => ['required_if:reporter_type,witness', 'nullable', 'string', 'max:120'],
            'reporter_contact' => ['required_if:reporter_type,witness', 'nullable', 'string', 'max:30'],
            'location' => ['required', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'victim_info' => ['nullable', 'array'],
            'victim_info.name' => ['nullable', 'string', 'max:120'],
            'victim_info.age' => ['nullable', 'integer', 'between:0,130'],
            'victim_info.sex' => ['nullable', 'string', 'max:20'],
            'witness_info' => ['nullable', 'array'],
        ]);

        $teamId = $data['team_id'] ?? null;
        unset($data['team_id']);
        $data['reporter_name'] = isset($data['reporter_name']) && trim($data['reporter_name']) !== ''
            ? trim($data['reporter_name'])
            : null;
        $data['reporter_contact'] = isset($data['reporter_contact']) && trim($data['reporter_contact']) !== ''
            ? trim($data['reporter_contact'])
            : null;
        $data['lat'] = $data['lat'] ?? 14.8153;
        $data['lng'] = $data['lng'] ?? 120.9094;
        if (isset($data['victim_info']) && is_array($data['victim_info'])) {
            $data['victim_info'] = [
                'name' => isset($data['victim_info']['name']) && trim((string) $data['victim_info']['name']) !== ''
                    ? trim((string) $data['victim_info']['name'])
                    : null,
                'age' => isset($data['victim_info']['age']) && trim((string) $data['victim_info']['age']) !== ''
                    ? (int) $data['victim_info']['age']
                    : null,
                'sex' => isset($data['victim_info']['sex']) && trim((string) $data['victim_info']['sex']) !== ''
                    ? trim((string) $data['victim_info']['sex'])
                    : null,
            ];
        }
        $priority = IncidentType::find($data['incident_type_id'])->priority; // priority comes from the incident type

        $report = DB::transaction(function () use ($data, $priority, $teamId) {
            $report = EmergencyReport::create($data + [
                'reference' => EmergencyReport::generateReference(),
                'source' => 'emergency-call',
                'priority' => $priority,
                'description' => $data['description'] ?? '',
            ]);

            $selectedTeam = $teamId ? ResponseTeam::find($teamId) : null;

            return $this->dispatch->dispatch($report, $selectedTeam);
        });

        return redirect()->route('dispatcher.reports.show', $report)->with('success', "Dispatched {$report->team->team_name}.");
    }
}
