<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\{EmergencyReport, Vehicle};
use Inertia\Inertia;

class MapController extends Controller
{
    public function index()
    {
        return Inertia::render('Dispatcher/Map', [
            // closure => only evaluated when requested, so polling with only:['vehicles'] stays cheap
            'vehicles' => fn () => Vehicle::with('team:id,team_name', 'report:id,reference,location')->whereNotNull('lat')->get()->map(fn ($v) => [
                'id' => $v->id, 'teamName' => $v->team?->team_name, 'type' => $v->vehicle_type, 'plate' => $v->plate_number,
                'lat' => $v->lat, 'lng' => $v->lng, 'status' => $v->status, 'speed' => $v->speed, 'heading' => $v->heading,
                'report' => $v->report?->only('id', 'reference', 'location'), 'updatedAt' => $v->last_updated_at?->toIso8601String(),
            ]),
            'reports' => EmergencyReport::with('incidentType:id,name,color')->active()
                ->get()->map(fn ($r) => ['id' => $r->id, 'reference' => $r->reference, 'type' => $r->incidentType->name, 'color' => $r->incidentType->color,
                    'location' => $r->location, 'lat' => $r->lat, 'lng' => $r->lng, 'priority' => $r->priority, 'status' => $r->status]),
        ]);
    }
}
