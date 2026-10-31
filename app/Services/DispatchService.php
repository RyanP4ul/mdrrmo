<?php

namespace App\Services;

use App\Models\{EmergencyReport, Hospital, ResidentNotification, ResponseTeam};
use App\Support\Audit;

class DispatchService
{
    /** Least-busy active team that is currently on shift; falls back to any active team. */
    public function autoAssignTeam(): ?ResponseTeam
    {
        $busy = fn ($q) => $q->whereIn('status', ['acknowledged', 'dispatched']);

        return ResponseTeam::where('status', 'active')
                ->whereHas('shifts', fn ($q) => $q->where('status', 'on-shift'))
                ->withCount(['reports as busy_count' => $busy])->orderBy('busy_count')->first()
            ?? ResponseTeam::where('status', 'active')
                ->withCount(['reports as busy_count' => $busy])->orderBy('busy_count')->first();
    }

    /** Members of the team's current on-shift roster. */
    public function onShiftMembers(ResponseTeam $team)
    {
        $shift = $team->shifts()->where('status', 'on-shift')->with('members')->first();
        return $shift ? $shift->members : collect();
    }

    public function dispatch(EmergencyReport $report, ?ResponseTeam $team = null): EmergencyReport
    {
        $team ??= $this->autoAssignTeam();
        abort_unless($team, 422, 'No active response team is available.');

        $driver = $this->onShiftMembers($team)->first(fn ($m) => $m->user_id && stripos((string) $m->role, 'driver') !== false)
            ?? $team->members()->whereNotNull('user_id')->first();

        $report->update([
            'response_team_id'   => $team->id,
            'assigned_driver_id' => $driver?->user_id,
            'status'             => 'dispatched',
            'acknowledged_by'    => $report->acknowledged_by ?? auth()->user()?->getKey(),
            'acknowledged_by_type' => $report->acknowledged_by_type ?? auth()->user()?->getMorphClass(),
            'acknowledged_at'    => $report->acknowledged_at ?? now(),
        ]);

        // Tell the reporting resident
        if ($report->reported_by) {
            ResidentNotification::create([
                'user_id' => $report->reported_by, 'type' => 'report_update',
                'title' => 'Help is on the way',
                'message' => "{$team->team_name} has been dispatched to {$report->location}.",
                'related_type' => EmergencyReport::class, 'related_id' => $report->id,
            ]);
        }

        Audit::record('ASSIGN_TEAM', "Report {$report->reference} dispatched to {$team->team_name}");
        return $report->refresh();
    }

    /** Nearest hospitals (haversine), ETA assumes 40 km/h average. Prefers matching specialization. */
    public function recommendHospitals(EmergencyReport $report, int $limit = 3)
    {
        $want = ['Medical Emergency' => 'Emergency', 'Trauma' => 'Trauma', 'Vehicular' => 'Trauma', 'Fire' => 'Burn'][$report->incidentType->name] ?? null;

        return Hospital::all()->map(function ($h) use ($report, $want) {
            $km = $this->km($report->lat, $report->lng, $h->lat, $h->lng);
            return [
                'id' => $h->id, 'name' => $h->name, 'address' => $h->address, 'lat' => $h->lat, 'lng' => $h->lng,
                'distance' => round($km, 1), 'availableBeds' => $h->available_beds, 'contactNumber' => $h->contact_number,
                'specialization' => $h->specialization ?? [], 'eta' => max(1, (int) round($km / 40 * 60)),
                'match' => $want && in_array($want, $h->specialization ?? [], true),
            ];
        })->sortBy([['match', 'desc'], ['distance', 'asc']])->take($limit)->values();
    }

    private function km(float $a, float $b, float $c, float $d): float
    {
        $r = 6371; $dLat = deg2rad($c - $a); $dLng = deg2rad($d - $b);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(sqrt($h));
    }
}
