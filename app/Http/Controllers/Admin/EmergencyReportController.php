<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEmergencyReportRequest;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Services\EmergencyReportWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class EmergencyReportController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', EmergencyReport::class);
        $filters = $request->only('search', 'status', 'priority', 'type');

        return Inertia::render('Admin/Reports/Index', [
            'filters' => $filters,
            'types' => IncidentType::orderBy('name')->get(['id', 'name']),
            'reports' => EmergencyReport::with('incidentType:id,name,color')
                ->filter($filters)
                ->latest('reported_at')
                ->paginate(8)->withQueryString(),
        ]);
    }

    public function show(EmergencyReport $report)
    {
        Gate::authorize('view', $report);

        return Inertia::render('Admin/Reports/Show', [
            'report' => $report->load(['incidentType', 'team.members', 'reporter:id,first_name,last_name', 'driver:id,first_name,last_name', 'form']),
        ]);
    }

    public function update(UpdateEmergencyReportRequest $request, EmergencyReport $report, EmergencyReportWorkflow $workflow)
    {
        $data = $request->validated();

        $workflow->transition($report, $data['status'], $request->user(), $data['priority']);

        return back()->with('success', 'Report updated.');
    }
}
