<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Support\Audit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmergencyReportController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only('search', 'status', 'priority', 'type');

        return Inertia::render('Admin/Reports/Index', [
            'filters' => $filters,
            'types'   => IncidentType::orderBy('name')->get(['id', 'name']),
            'reports' => EmergencyReport::with('incidentType:id,name,color')
                ->filter($filters)
                ->latest('reported_at')
                ->paginate(8)->withQueryString(),
        ]);
    }

    public function show(EmergencyReport $report)
    {
        return Inertia::render('Admin/Reports/Show', [
            'report' => $report->load(['incidentType', 'team.members', 'reporter:id,first_name,last_name', 'driver:id,first_name,last_name', 'form']),
        ]);
    }

    public function update(Request $request, EmergencyReport $report)
    {
        $data = $request->validate([
            'status'   => ['required', 'in:pending,acknowledged,dispatched,resolved,invalid'],
            'priority' => ['required', 'in:low,medium,high,critical'],
        ]);
        $data['resolved_at'] = $data['status'] === 'resolved' ? now() : null;

        $report->update($data);
        Audit::record('UPDATE_REPORT', "Report {$report->reference} set to {$data['status']}");

        return back()->with('success', 'Report updated.');
    }
}
