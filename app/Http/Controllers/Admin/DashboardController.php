<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $period = in_array($request->query('period'), ['today', 'weekly', 'monthly']) ? $request->query('period') : 'monthly';
        $since = match ($period) {
            'today'  => now()->startOfDay(),
            'weekly' => now()->subWeek(),
            default  => now()->subMonth(),
        };

        $byStatus = EmergencyReport::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('Admin/Dashboard', [
            'period' => $period,
            'stats' => [
                'active'          => ($byStatus['pending'] ?? 0) + ($byStatus['acknowledged'] ?? 0) + ($byStatus['dispatched'] ?? 0),
                'pending'         => $byStatus['pending'] ?? 0,
                'dispatched'      => $byStatus['dispatched'] ?? 0,
                'resolvedToday'   => EmergencyReport::where('status', 'resolved')->where('resolved_at', '>=', now()->startOfDay())->count(),
                'invalid'         => $byStatus['invalid'] ?? 0,
                'totalReports'    => $byStatus->sum(),
                'residents'       => ResidentUser::count(),
                'activeResidents' => ResidentUser::where('status', 'active')->count(),
                'pendingApprovals'=> ResidentUser::where('registration_status', 'pending')->count(),
            ],
            // Real data – replaces the fake "count / 30" maths in the old dashboard
            'byType' => IncidentType::query()
                ->withCount(['reports as value' => fn ($q) => $q->where('reported_at', '>=', $since)])
                ->get(['id', 'name', 'color'])
                ->filter(fn ($t) => $t->value > 0)->values(),
            'heatmap' => EmergencyReport::with('incidentType:id,name,color')
                ->where('reported_at', '>=', now()->subMonths(3))
                ->get(['id', 'incident_type_id', 'lat', 'lng', 'priority'])
                ->map(fn ($r) => [
                    'lat' => $r->lat, 'lng' => $r->lng,
                    'type' => $r->incidentType->name, 'color' => $r->incidentType->color,
                    'intensity' => ['low' => .3, 'medium' => .5, 'high' => .75, 'critical' => 1][$r->priority],
                ]),
            'recentActivity' => AuditLog::latest('created_at')->limit(15)->get()->map(fn ($l) => [
                'id' => $l->id, 'action' => $l->details, 'user' => $l->user_name, 'time' => $l->created_at->diffForHumans(),
            ]),
        ]);
    }
}
