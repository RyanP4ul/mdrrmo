<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Services\DispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private DispatchService $dispatch) {}

    public function index()
    {
        $team = $this->dispatch->autoAssignTeam();

        return Inertia::render('Dispatcher/Dashboard', [
            'incoming' => EmergencyReport::with(['incidentType:id,name,color', 'team:id,team_name'])->active()
                ->orderByRaw("status = 'pending' desc")->latest('reported_at')->get(),
            'counts' => EmergencyReport::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'types' => IncidentType::orderBy('name')->get(['id', 'name', 'priority']),
            'autoTeam' => $team ? ['id' => $team->id, 'name' => $team->team_name,
                'members' => $this->dispatch->onShiftMembers($team)->map->only('id', 'name', 'role')->values()] : null,
        ]);
    }

    /** Create (phone call) or dispatch (existing report). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'report_id' => ['nullable', 'exists:emergency_reports,id'],
            'incident_type_id' => ['required', 'exists:incident_types,id'],
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

        $priority = IncidentType::find($data['incident_type_id'])->priority; // priority comes from the incident type
        $fields = collect($data)->except('report_id')->all();

        $report = DB::transaction(function () use ($data, $fields, $priority) {
            if (isset($data['report_id'])) {
                $report = EmergencyReport::findOrFail($data['report_id']);
                $report->update($fields + ['priority' => $priority]);
            } else {
                $report = EmergencyReport::create($fields + [
                    'reference' => EmergencyReport::generateReference(),
                    'source' => 'emergency-call', 'priority' => $priority, 'description' => $data['description'] ?? '',
                ]);
            }

            return $this->dispatch->dispatch($report);
        });

        return redirect()->route('dispatcher.reports.show', $report)->with('success', "Dispatched {$report->team->team_name}.");
    }
}
