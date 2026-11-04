<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\DriverLocation;
use App\Models\EmergencyReport;
use Inertia\Inertia;

class MapController extends Controller
{
    public function index()
    {
        $snapshotAt = now()->toISOString();

        return Inertia::render('Dispatcher/Map', [
            'snapshotAt' => $snapshotAt,
            'drivers' => fn () => DriverLocation::with('driver.activeAssignments.trip', 'driver.activeAssignments.team', 'driver.activeMemberships.team')
                ->whereHas('driver', fn ($query) => $query->where('status', 'active'))->orderBy('driver_id')->get()
                ->map(fn (DriverLocation $location): array => $location->mapData($snapshotAt)),
            'reports' => EmergencyReport::with('incidentType:id,name,color')->active()
                ->get(['id', 'incident_type_id', 'reference', 'location', 'lat', 'lng', 'priority', 'status'])->map(fn ($r) => ['id' => $r->id, 'reference' => $r->reference, 'type' => $r->incidentType->name, 'color' => $r->incidentType->color,
                    'location' => $r->location, 'lat' => $r->lat, 'lng' => $r->lng, 'priority' => $r->priority, 'status' => $r->status]),
        ]);
    }
}
