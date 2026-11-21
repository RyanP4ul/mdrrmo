<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Services\DispatchService;
use App\Services\EmergencyReportWorkflow;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
        $f = $request->only('search', 'type', 'priority', 'status', 'tab');
        $tab = $f['tab'] ?? 'emergency';

        $scheduledReports = $tab === 'scheduled'
            ? ServiceSchedule::query()
                ->with(['resident:id,first_name,last_name,contact_number', 'team:id,team_name', 'driverTicket.driver:id,first_name,last_name'])
                ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($query) => $query
                    ->where('service_type', 'like', "%{$s}%")
                    ->orWhere('service_details', 'like', "%{$s}%")
                    ->orWhere('address', 'like', "%{$s}%")
                    ->orWhere('destination_address', 'like', "%{$s}%")
                    ->orWhereHas('resident', fn ($r) => $r->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%"))))
                ->when($f['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('preferred_date')
                ->orderByDesc('preferred_time')
                ->paginate(6)
                ->withQueryString()
                ->through(fn (ServiceSchedule $s): array => [
                    'id' => $s->id,
                    'reference' => 'SCH-'.str_pad((string) $s->id, 5, '0', STR_PAD_LEFT),
                    'service_type' => $s->service_type,
                    'service_details' => $s->service_details,
                    'preferred_date' => $s->preferred_date?->toDateString(),
                    'preferred_time' => Carbon::parse($s->preferred_time)->format('H:i'),
                    'address' => $s->address,
                    'destination_address' => $s->destination_address,
                    'status' => $s->status,
                    'resident' => $s->resident?->full_name,
                    'contact' => $s->resident?->contact_number,
                    'team_name' => $s->team?->team_name,
                    'has_driver_ticket' => $s->driverTicket !== null,
                    'driver_ticket' => $s->driverTicket ? [
                        'driver_name' => $s->driverTicket->driver?->full_name,
                        'driver_section' => $s->driverTicket->driver_section ?? [],
                    ] : null,
                ])
            : null;

        return Inertia::render('Dispatcher/Reports/Index', [
            'filters' => $f,
            'types' => IncidentType::orderBy('name')->get(['id', 'name']),
            'reports' => EmergencyReport::with(['incidentType:id,name,color', 'form', 'driver:id,first_name,last_name'])
                ->when($tab === 'driver',
                    fn ($query) => $query->withDriverForm(),
                    fn ($query) => $query->whereIn('status', ['resolved', 'invalid']))
                ->filter($f)->latest('reported_at')->paginate(6)->withQueryString(),
            'scheduledReports' => $scheduledReports,
        ]);
    }

    public function show(EmergencyReport $report)
    {
        Gate::authorize('view', $report);
        $report->load(['incidentType', 'team.members', 'driver:id,first_name,last_name', 'form']);
        $team = $report->team ?? $this->dispatcher->autoAssignTeam();

        return Inertia::render('Dispatcher/Reports/Show', [
            'report' => array_merge($report->toArray(), [
                'photo_url' => $report->photo_path ? route('dispatcher.reports.photo', $report) : null,
            ]),
            'team' => $team ? ['id' => $team->id, 'name' => $team->team_name, 'assigned' => (bool) $report->team,
                'onShift' => $this->dispatcher->onShiftMembers($team)->map->only('id', 'name', 'role')->values()] : null,
            'teams' => ResponseTeam::where('status', 'active')->get(['id', 'team_name']),
            'hospitals' => $this->dispatcher->recommendHospitals($report),
            'googleMapsApiKey' => config('services.google_maps.key'),
        ]);
    }

    public function photo(EmergencyReport $report)
    {
        Gate::authorize('view', $report);
        abort_unless($report->photo_path && Storage::disk('r2')->exists($report->photo_path), 404);

        return Storage::disk('r2')->response($report->photo_path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
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
