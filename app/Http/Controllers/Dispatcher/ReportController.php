<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\Vehicle;
use App\Services\DispatchService;
use App\Services\EmergencyReportWorkflow;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function __construct(private DispatchService $dispatcher) {}

    public function getReports(Request $request)
    {
        $f = $request->only('search', 'type', 'priority');

        return EmergencyReport::with('incidentType:id,name,color')
            ->where('assigned_driver_id', $request->user()->getKey())
            ->whereNotIn('status', ['resolved', 'invalid'])
            ->filter($f)->latest('reported_at')->paginate(6)->withQueryString();
    }

    /** History: resolved / invalid only */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', EmergencyReport::class);
        $f = $request->only('search', 'type', 'priority', 'status', 'tab');
        $tab = $f['tab'] ?? 'emergency';

        $scheduledReports = $tab === 'scheduled'
            ? ServiceSchedule::query()
                ->with(['resident:id,first_name,last_name,contact_number', 'team:id,team_name', 'driverTicket.driver:id,first_name,last_name'])
                ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($query) => $query
                    ->where('service_type', 'like', "%{$s}%")
                    ->orWhere('service_details', 'like', "%{$s}%")
                    ->orWhere('address', 'like', "%{$s}%")
                    ->orWhere('destination_address', 'like', "%{$s}%")
                    ->orWhereHas('resident', fn ($r) => $r->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%"))))
                ->when($f['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('preferred_date')
                ->orderByDesc('preferred_time')
                ->paginate(6)
                ->withQueryString()
                ->through(fn (ServiceSchedule $s): array => [
                    'id' => $s->id,
                    'reference' => 'SCH-'.str_pad((string) $s->id, 5, '0', STR_PAD_LEFT),
                    'service_type' => $s->service_type,
                    'service_details' => $s->service_details,
                    'preferred_date' => $s->preferred_date?->toDateString(),
                    'preferred_time' => Carbon::parse($s->preferred_time)->format('H:i'),
                    'address' => $s->address,
                    'destination_address' => $s->destination_address,
                    'status' => $s->status,
                    'resident' => $s->resident?->full_name,
                    'contact' => $s->resident?->contact_number,
                    'team_name' => $s->team?->team_name,
                    'has_driver_ticket' => $s->driverTicket !== null,
                    'driver_ticket' => $s->driverTicket ? [
                        'driver_name' => $s->driverTicket->driver?->full_name,
                        'driver_section' => $s->driverTicket->driver_section ?? [],
                    ] : null,
                ])
            : null;

        return Inertia::render('Dispatcher/Reports/Index', [
            'filters' => $f,
            'types' => IncidentType::orderBy('name')->get(['id', 'name']),
            'reports' => EmergencyReport::with(['incidentType:id,name,color', 'form', 'driver:id,first_name,last_name'])
                ->when($tab === 'driver',
                    fn ($query) => $query->withDriverForm(),
                    fn ($query) => $query->whereIn('status', ['resolved', 'invalid']))
                ->filter($f)->latest('reported_at')->paginate(6)->withQueryString(),
            'scheduledReports' => $scheduledReports,
        ]);
    }

    public function show(EmergencyReport $report)
    {
        Gate::authorize('view', $report);
        $report->load([
            'incidentType',
            'team.members',
            'driver:id,first_name,last_name',
            'form',
            'parentReport:id,reference,status,response_team_id',
            'requestedByTeam:id,team_name',
            'assignedVehicle:id,name,plate_number,vehicle_type,status,assigned_report_id',
            'activeDispatchAssignment.vehicle:id,name,plate_number,vehicle_type,status',
        ]);
        $isBackup = $report->isBackupRequest();
        $requestingTeamId = $isBackup
            ? ($report->requested_by_team_id ?? $report->parentReport?->response_team_id)
            : null;
        $team = $report->team ?? $this->dispatcher->autoAssignTeam(
            ignoreReportId: $report->getKey(),
            excludeTeamId: $requestingTeamId,
        );
        $assignedVehicle = $report->assignedVehicle ?? $report->activeDispatchAssignment?->vehicle;

        $activeReportsByTeam = EmergencyReport::query()
            ->whereIn('status', ['acknowledged', 'dispatched'])
            ->whereNotNull('response_team_id')
            ->where('id', '!=', $report->getKey())
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
        $busyTeamIds = $this->dispatcher->busyTeamIds($report->getKey());
        $inUseVehicleIds = $this->dispatcher->inUseVehicleIds($report->getKey(), $isBackup ? $report : null);
        $excludedVehicleIds = $isBackup ? $this->dispatcher->backupExcludedVehicleIds($report) : [];

        $teams = ResponseTeam::query()
            ->where('status', 'active')
            ->with(['vehicles', 'activeVehicle'])
            ->orderBy('team_name')
            ->get()
            ->map(function (ResponseTeam $t) use ($busyTeamIds, $activeReportsByTeam, $activeScheduleTeamIds, $inUseVehicleIds): array {
                $defaultVehicle = $t->resolveActiveVehicle();
                $activeRef = $activeReportsByTeam->get($t->id)?->reference
                    ?? (in_array($t->id, $activeScheduleTeamIds, true) ? 'Scheduled service' : null);

                return [
                    'id' => $t->id,
                    'team_name' => $t->team_name,
                    'is_available' => ! in_array($t->id, $busyTeamIds, true),
                    'active_report_reference' => $activeRef,
                    'default_vehicle' => $defaultVehicle ? [
                        'id' => $defaultVehicle->id,
                        'name' => $defaultVehicle->displayName(),
                        'plate_number' => $defaultVehicle->plate_number,
                        'vehicle_type' => $defaultVehicle->vehicle_type,
                        'vehicle_type_label' => Vehicle::typeLabel($defaultVehicle->vehicle_type),
                        'status' => $defaultVehicle->status,
                        'is_available' => $defaultVehicle->status === 'available'
                            && $defaultVehicle->assigned_report_id === null
                            && ! in_array($defaultVehicle->id, $inUseVehicleIds, true),
                    ] : null,
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
                    && ($v->assigned_report_id === null || $v->assigned_report_id === $report->getKey())
                    && ! in_array($v->id, $inUseVehicleIds, true),
            ])
            ->values();

        return Inertia::render('Dispatcher/Reports/Show', [
            'report' => array_merge($report->toArray(), [
                'photo_url' => $report->photo_path ? route('dispatcher.reports.photo', $report) : null,
                'is_backup' => $isBackup,
                'requested_vehicle_type' => $report->requested_vehicle_type,
                'requested_vehicle_label' => Vehicle::typeLabel($report->requested_vehicle_type),
                'excluded_vehicle_ids' => $excludedVehicleIds,
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
            ]),
            'team' => $team ? ['id' => $team->id, 'name' => $team->team_name, 'assigned' => (bool) $report->team,
                'onShift' => $this->dispatcher->onShiftMembers($team)->map->only('id', 'name', 'role')->values()] : null,
            'teams' => $teams,
            'vehicles' => $vehicles,
            'hospitals' => $this->dispatcher->recommendHospitals($report),
            'googleMapsApiKey' => config('services.google_maps.key'),
        ]);
    }

    public function photo(EmergencyReport $report)
    {
        Gate::authorize('view', $report);
        abort_unless($report->photo_path && Storage::disk('r2')->exists($report->photo_path), 404);

        return Storage::disk('r2')->response($report->photo_path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function assign(Request $request, EmergencyReport $report)
    {
        Gate::authorize('dispatch', $report);
        $data = $request->validate([
            'team_id' => ['nullable', 'exists:response_teams,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);
        $teamId = $data['team_id'] ?? null;
        $vehicleId = $data['vehicle_id'] ?? null;
        $this->dispatcher->dispatch(
            $report,
            $teamId ? ResponseTeam::find($teamId) : null,
            null,
            false,
            $vehicleId ? Vehicle::find($vehicleId) : null,
        );

        return back()->with('success', 'Response team dispatched.');
    }

    public function acknowledge(Request $request, EmergencyReport $report, EmergencyReportWorkflow $workflow)
    {
        Gate::authorize('acknowledge', $report);
        $workflow->transition($report, 'acknowledged', $request->user());

        return back()->with('success', 'Report acknowledged.');
    }

    public function invalidate(Request $request, EmergencyReport $report, EmergencyReportWorkflow $workflow)
    {
        Gate::authorize('invalidate', $report);
        $workflow->transition($report, 'invalid', $request->user());

        return back()->with('success', 'Report marked invalid.');
    }
}
