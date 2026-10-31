<?php

namespace App\Http\Controllers\Driver;

use App\Events\VehicleLocationUpdated;
use App\Http\Controllers\Controller;
use App\Models\{EmergencyReport, ReportForm, Vehicle};
use Illuminate\Http\Request;
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
            'active' => $this->mine($request)->whereIn('status', ['dispatched', 'acknowledged'])->latest('reported_at')->get(),
            'doneToday' => $this->mine($request)->where('status', 'resolved')->whereDate('resolved_at', today())->count(),
            'vehicle' => Vehicle::whereHas('team.members', fn ($q) => $q->where('user_id', $request->user()->id))->first(),
        ]);
    }

    public function assignments(Request $request)
    {
        return Inertia::render('Driver/Assignments', [
            'reports' => $this->mine($request)->latest('reported_at')->paginate(8)->withQueryString(),
        ]);
    }

    /** Driver advances an assignment: dispatched -> (en-route/on-scene) -> resolved */
    public function updateStatus(Request $request, EmergencyReport $report)
    {
        abort_unless($report->assigned_driver_id === $request->user()->id, 403);
        $d = $request->validate(['vehicle_status' => ['required', 'in:en-route,on-scene,returning,available']]);

        $vehicle = Vehicle::whereHas('team.members', fn ($q) => $q->where('user_id', $request->user()->id))->first();
        $vehicle?->update(['status' => $d['vehicle_status'], 'assigned_report_id' => $d['vehicle_status'] === 'available' ? null : $report->id]);

        if ($d['vehicle_status'] === 'available') {
            $report->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
        if ($vehicle) event(new VehicleLocationUpdated($vehicle));

        return back()->with('success', 'Status updated.');
    }

    public function reports(Request $request)
    {
        return Inertia::render('Driver/Reports', [
            'reports' => $this->mine($request)->whereIn('status', ['dispatched', 'resolved'])->with('form')->latest('reported_at')->paginate(6)->withQueryString(),
        ]);
    }

    public function saveForm(Request $request, EmergencyReport $report)
    {
        abort_unless($report->assigned_driver_id === $request->user()->id, 403);
        $d = $request->validate(['driver_section' => ['required', 'array'], 'emergency_section' => ['required', 'array']]);
        ReportForm::updateOrCreate(['emergency_report_id' => $report->id], $d);

        return back()->with('success', 'Patient care report saved.');
    }

    public function tracking(Request $request)
    {
        return Inertia::render('Driver/Tracking', [
            'vehicle' => Vehicle::with('team:id,team_name', 'report:id,reference,location,lat,lng')
                ->whereHas('team.members', fn ($q) => $q->where('user_id', $request->user()->id))->first(),
        ]);
    }

    /** Called every few seconds by the driver's phone (navigator.geolocation.watchPosition). */
    public function ping(Request $request)
    {
        $d = $request->validate(['lat' => ['required', 'numeric'], 'lng' => ['required', 'numeric'],
            'speed' => ['nullable', 'numeric'], 'heading' => ['nullable', 'numeric']]);

        $vehicle = Vehicle::whereHas('team.members', fn ($q) => $q->where('user_id', $request->user()->id))->firstOrFail();
        $vehicle->update(['lat' => $d['lat'], 'lng' => $d['lng'], 'speed' => (int) round(($d['speed'] ?? 0) * 3.6),
            'heading' => (int) ($d['heading'] ?? 0), 'last_updated_at' => now()]);
        event(new VehicleLocationUpdated($vehicle));

        return response()->noContent();
    }
}
