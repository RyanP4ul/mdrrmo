<?php

namespace App\Http\Controllers\Driver;

use App\Events\DriverLocationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePatientReportRequest;
use App\Http\Requests\StoreDriverLocationPingRequest;
use App\Models\DriverLocation;
use App\Models\EmergencyReport;
use App\Models\ReportForm;
use App\Services\DriverResponseProgress;
use App\Services\EmergencyReportWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class DriverController extends Controller
{
    private function mine(Request $r)
    {
        return EmergencyReport::with('incidentType:id,name,color')->where('assigned_driver_id', $r->user()->id);
    }

    public function dashboard(Request $request)
    {
        return Inertia::render('Driver/Dashboard', [
            'active' => $this->mine($request)->whereIn('status', ['dispatched', 'acknowledged'])->with('trip')->latest('reported_at')->get(),
            'doneToday' => $this->mine($request)->where('status', 'resolved')->whereDate('resolved_at', today())->count(),

        ]);
    }

    public function assignments(Request $request)
    {
        return Inertia::render('Driver/Assignments', [
            'reports' => $this->mine($request)->latest('reported_at')->paginate(8)->withQueryString(),
        ]);
    }

    /** Driver advances an assignment: dispatched -> (en-route/on-scene) -> resolved */
    public function updateStatus(Request $request, EmergencyReport $report, EmergencyReportWorkflow $workflow)
    {
        $request->merge(['response_status' => $request->input('response_status', $request->input('vehicle_status'))]);
        $data = $request->validate(['response_status' => ['required', 'in:en-route,on-scene,returning,available']]);
        app(DriverResponseProgress::class)->advance($request->user(), $report, $data['response_status']);

        return back()->with('success', 'Response progress updated.');
    }

    public function reports(Request $request)
    {
        return Inertia::render('Driver/Reports', [
            'reports' => $this->mine($request)->whereIn('status', ['dispatched', 'resolved'])->with('form')->latest('reported_at')->paginate(6)->withQueryString(),
        ]);
    }

    public function saveForm(SavePatientReportRequest $request, EmergencyReport $report)
    {
        $validated = $request->validated();
        ReportForm::updateOrCreate(['emergency_report_id' => $report->id], $validated);

        return back()->with('success', 'Patient care report saved.');
    }

    public function tracking(Request $request)
    {
        return Inertia::render('Driver/Tracking', [
            'location' => DriverLocation::find($request->user()->id)?->mapData(),
        ]);
    }

    /** Called every few seconds by the driver's phone (navigator.geolocation.watchPosition). */
    public function ping(StoreDriverLocationPingRequest $request)
    {
        $d = $request->validated();

        $location = DriverLocation::updateOrCreate(['driver_id' => $request->user()->id], [
            'session_id' => (string) Str::uuid(), 'lat' => $d['lat'], 'lng' => $d['lng'],
            'accuracy' => $d['accuracy'], 'speed' => (int) round(($d['speed'] ?? 0) * 3.6),
            'heading' => (int) round($d['heading'] ?? 0), 'observed_at' => now(), 'expires_at' => now()->addMinutes(3),
        ]);
        DriverLocationUpdated::dispatch($location);

        return response()->noContent();
    }
}
