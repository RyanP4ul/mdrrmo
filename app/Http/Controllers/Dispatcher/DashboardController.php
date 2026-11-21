<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResponseTeam;
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
        $teams = ResponseTeam::query()
            ->where('status', 'active')
            ->with([
                'members' => fn ($q) => $q->where('status', 'active')->with('user:id,status'),
                'vehicles',
                'activeVehicle',
            ])
            ->orderBy('team_name')
            ->get()
            ->map(function (ResponseTeam $t): array {
                $onShift = $this->dispatch->onShiftMembers($t);
                $members = ($onShift->isNotEmpty() ? $onShift : $t->members)
                    ->map(fn ($m): array => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'role' => $m->role,
                    ])->values()->all();
                $vehicle = $t->resolveActiveVehicle();
                $vehicleLabel = $vehicle
                    ? ($vehicle->name ?: ucfirst(str_replace('-', ' ', $vehicle->vehicle_type)).' '.$vehicle->plate_number)
                    : null;

                return [
                    'id' => $t->id,
                    'name' => $t->team_name,
                    'shift_team' => $t->shift_team,
                    'vehicle_name' => $vehicleLabel,
                    'members' => $members,
                ];
            })
            ->values();

        $autoVehicle = $team?->resolveActiveVehicle();
        $autoVehicleLabel = $autoVehicle
            ? ($autoVehicle->name ?: ucfirst(str_replace('-', ' ', $autoVehicle->vehicle_type)).' '.$autoVehicle->plate_number)
            : null;

        return Inertia::render('Dispatcher/Dashboard', [
            'incoming' => EmergencyReport::with(['incidentType:id,name,color', 'team:id,team_name'])->active()
                ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->orderByRaw("status = 'pending' desc")->latest('reported_at')->orderByDesc('id')->get()
                ->map(fn (EmergencyReport $report): array => array_merge($report->toArray(), [
                    'photo_url' => $report->photo_path ? route('dispatcher.reports.photo', $report) : null,
                ])),
            'counts' => EmergencyReport::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'types' => IncidentType::orderBy('name')->get(['id', 'name', 'priority']),
            'autoTeam' => $team ? [
                'id' => $team->id,
                'name' => $team->team_name,
                'vehicle_name' => $autoVehicleLabel,
                'members' => $this->dispatch->onShiftMembers($team)->map->only('id', 'name', 'role')->values(),
            ] : null,
            'teams' => $teams,
        ]);
    }

    /** Create (phone call) or dispatch (existing report). */
    public function store(Request $request)
    {
        if ($request->filled('report_id')) {
            $data = $request->validate([
                'report_id' => ['required', 'integer', 'exists:emergency_reports,id'],
                'team_id' => ['nullable', 'integer', 'exists:response_teams,id'],
            ]);

            $report = EmergencyReport::findOrFail($data['report_id']);
            Gate::authorize('dispatch', $report);

            $selectedTeam = ! empty($data['team_id']) ? ResponseTeam::find($data['team_id']) : null;
            $report = $this->dispatch->dispatch($report, $selectedTeam);

            return redirect()->route('dispatcher.reports.show', $report)->with('success', "Dispatched {$report->team->team_name}.");
        }

        $data = $request->validate([
            'incident_type_id' => ['required', 'exists:incident_types,id'],
            'team_id' => ['nullable', 'integer', 'exists:response_teams,id'],
            'reporter_name' => ['required', 'string', 'max:120'],
            'reporter_contact' => ['required', 'string', 'max:30'],
            'location' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reporter_type' => ['required', 'in:victim,witness'],
            'victim_info' => ['nullable', 'array'],
            'witness_info' => ['nullable', 'array'],
        ]);

        $teamId = $data['team_id'] ?? null;
        unset($data['team_id']);
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
