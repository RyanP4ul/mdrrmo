<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\{EmergencyReport, IncidentType, ResponseTeam};
use App\Services\DispatchService;
use Illuminate\Http\Request;
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
        $f = $request->only('search', 'type', 'priority');

        return Inertia::render('Dispatcher/Reports/Index', [
            'filters' => $f,
            'types' => IncidentType::orderBy('name')->get(['id', 'name']),
            'reports' => EmergencyReport::with('incidentType:id,name,color')->whereIn('status', ['resolved', 'invalid'])
                ->filter($f)->latest('reported_at')->paginate(6)->withQueryString(),
        ]);
    }

    public function show(EmergencyReport $report)
    {
        $report->load(['incidentType', 'team.members', 'driver:id,first_name,last_name']);
        $team = $report->team ?? $this->dispatcher->autoAssignTeam();

        return Inertia::render('Dispatcher/Reports/Show', [
            'report' => $report,
            'team' => $team ? ['id' => $team->id, 'name' => $team->team_name, 'assigned' => (bool) $report->team,
                'onShift' => $this->dispatcher->onShiftMembers($team)->map->only('id', 'name', 'role')->values()] : null,
            'teams' => ResponseTeam::where('status', 'active')->get(['id', 'team_name']),
            'hospitals' => $this->dispatcher->recommendHospitals($report),
        ]);
    }

    public function assign(Request $request, EmergencyReport $report)
    {
        $teamId = $request->validate(['team_id' => ['nullable', 'exists:response_teams,id']])['team_id'] ?? null;
        $this->dispatcher->dispatch($report, $teamId ? ResponseTeam::find($teamId) : null);

        return back()->with('success', 'Response team dispatched.');
    }

    public function acknowledge(EmergencyReport $report)
    {
        $report->update([
            'status' => 'acknowledged',
            'acknowledged_by' => auth()->user()->getKey(),
            'acknowledged_by_type' => auth()->user()->getMorphClass(),
            'acknowledged_at' => now(),
        ]);
        return back()->with('success', 'Report acknowledged.');
    }

    public function invalidate(EmergencyReport $report)
    {
        $report->update(['status' => 'invalid']);
        return back()->with('success', 'Report marked invalid.');
    }
}
