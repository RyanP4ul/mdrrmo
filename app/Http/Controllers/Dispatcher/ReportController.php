<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResponseTeam;
use App\Services\DispatchService;
use App\Services\EmergencyReportWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        $f = $request->only('search', 'type', 'priority', 'tab');

        return Inertia::render('Dispatcher/Reports/Index', [
            'filters' => $f,
            'types' => IncidentType::orderBy('name')->get(['id', 'name']),
            'reports' => EmergencyReport::with(['incidentType:id,name,color', 'form', 'driver:id,first_name,last_name'])
                ->when(($f['tab'] ?? '') === 'driver',
                    fn ($query) => $query->withDriverForm(),
                    fn ($query) => $query->whereIn('status', ['resolved', 'invalid']))
                ->filter($f)->latest('reported_at')->paginate(6)->withQueryString(),
        ]);
    }

    public function show(EmergencyReport $report)
    {
        Gate::authorize('view', $report);
        $report->load(['incidentType', 'team.members', 'driver:id,first_name,last_name', 'form']);
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
        Gate::authorize('dispatch', $report);
        $teamId = $request->validate(['team_id' => ['nullable', 'exists:response_teams,id']])['team_id'] ?? null;
        $this->dispatcher->dispatch($report, $teamId ? ResponseTeam::find($teamId) : null);

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
