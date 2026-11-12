<?php

namespace App\Http\Controllers;

use App\Models\EmergencyReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DriverFormController extends Controller
{
    public function __invoke(Request $request, EmergencyReport $report): Response
    {
        Gate::authorize('viewAny', EmergencyReport::class);
        Gate::authorize('view', $report);
        abort_unless(EmergencyReport::whereKey($report->id)->withDriverForm()->exists(), 404);
        $report->loadMissing(['form', 'driver', 'incidentType']);
        $role = $request->routeIs('admin.*') ? 'admin' : 'dispatcher';

        return Inertia::render('Reports/DriverForm', [
            'report' => [
                'id' => $report->id,
                'reference' => $report->reference,
                'status' => $report->status,
                'incident' => $report->incidentType?->name,
                'driver' => $report->driver?->full_name,
            ],
            'driverSection' => $report->form->driver_section,
            'backUrl' => route($role.'.reports.index', ['tab' => 'driver']),
            'reportUrl' => route($role.'.reports.show', $report),
        ]);
    }
}
