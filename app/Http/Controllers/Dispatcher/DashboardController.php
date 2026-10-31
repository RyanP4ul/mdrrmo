<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\{EmergencyReport, IncidentType};
use App\Services\DispatchService;
use Illuminate\Http\Request;
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
            'report_id'        => ['nullable', 'exists:emergency_reports,id'],
            'incident_type_id' => ['required', 'exists:incident_types,id'],
            'reporter_name'    => ['required', 'string', 'max:120'],
            'reporter_contact' => ['required', 'string', 'max:30'],
            'location'         => ['required', 'string', 'max:255'],
            'lat'              => ['required', 'numeric', 'between:-90,90'],
            'lng'              => ['required', 'numeric', 'between:-180,180'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'reporter_type'    => ['required', 'in:victim,witness'],
            'victim_info'      => ['nullable', 'array'],
            'witness_info'     => ['nullable', 'array'],
        ]);

        $priority = IncidentType::find($data['incident_type_id'])->priority; // priority comes from the incident type
        $fields = collect($data)->except('report_id')->all();

        if (isset($data['report_id'])) {
            $report = EmergencyReport::findOrFail($data['report_id']);
            $report->update($fields + ['priority' => $priority]);
        } else {
            $report = EmergencyReport::create($fields + [
                'reference' => 'RPT-' . now()->format('Y') . '-' . str_pad((string) (EmergencyReport::max('id') + 1), 4, '0', STR_PAD_LEFT),
                'source' => 'emergency-call', 'priority' => $priority, 'description' => $data['description'] ?? '',
            ]);
        }

        $report = $this->dispatch->dispatch($report);

        return redirect()->route('dispatcher.reports.show', $report)->with('success', "Dispatched {$report->team->team_name}.");
    }
}
