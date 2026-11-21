<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateEmergencyOperationsReportRequest;
use App\Http\Requests\UpdateEmergencyReportRequest;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ServiceSchedule;
use App\Services\ArchiveService;
use App\Services\EmergencyOperationsReportService;
use App\Services\EmergencyReportWorkflow;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class EmergencyReportController extends Controller
{
    public function __construct(
        private EmergencyOperationsReportService $operationsReports,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', EmergencyReport::class);
        $filters = $request->only('search', 'status', 'priority', 'type', 'tab');
        $tab = $filters['tab'] ?? 'emergency';
        $timezone = (string) config('reporting.timezone', 'Asia/Manila');
        $nowManila = CarbonImmutable::now($timezone);

        $scheduledReports = $tab === 'scheduled'
            ? ServiceSchedule::query()
                ->with(['resident:id,first_name,last_name,contact_number', 'team:id,team_name', 'driverTicket.driver:id,first_name,last_name'])
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($query) => $query
                    ->where('service_type', 'like', "%{$s}%")
                    ->orWhere('service_details', 'like', "%{$s}%")
                    ->orWhere('address', 'like', "%{$s}%")
                    ->orWhere('destination_address', 'like', "%{$s}%")
                    ->orWhereHas('resident', fn ($r) => $r->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%"))))
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('preferred_date')
                ->orderByDesc('preferred_time')
                ->paginate(8)
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

        return Inertia::render('Admin/Reports/Index', [
            'filters' => $filters,
            'types' => IncidentType::orderBy('name')->get(['id', 'name']),
            'reports' => EmergencyReport::with(['incidentType:id,name,color', 'form', 'driver:id,first_name,last_name'])
                ->when($tab === 'driver', fn ($query) => $query->withDriverForm())
                ->filter($filters)
                ->latest('reported_at')
                ->paginate(8)->withQueryString(),
            'scheduledReports' => $scheduledReports,
            'operationsDefaults' => [
                'timezone' => $timezone,
                'today_date' => $nowManila->toDateString(),
                'current_year' => (int) $nowManila->year,
                'current_month' => (int) $nowManila->month,
            ],
        ]);
    }

    public function operationsSummary(GenerateEmergencyOperationsReportRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', EmergencyReport::class);

        $summary = $this->operationsReports->buildSummary(
            $request->validated(),
            $request->user(),
            $request->includePatientDetails()
        );

        return response()->json($summary);
    }

    public function operationsPdf(GenerateEmergencyOperationsReportRequest $request): Response
    {
        Gate::authorize('viewAny', EmergencyReport::class);

        $validated = $request->validated();
        $mode = ($validated['mode'] ?? 'preview') === 'download' ? 'download' : 'preview';

        $report = $this->operationsReports->buildReport(
            $validated,
            $request->user(),
            $request->includePatientDetails()
        );

        $pdf = Pdf::loadView('reports.emergency-operations-pdf', [
            'report' => $report,
        ])->setPaper('a4', 'portrait');

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Times-Roman', 'normal');
        if ($font !== null) {
            $canvas->page_text(505, 808, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.15, 0.15, 0.15]);
        }

        $filename = 'emergency-operations-report-'.$report['period']['filename_slug'].'.pdf';
        $disposition = $mode === 'download' ? 'attachment' : 'inline';
        $output = $dompdf->output();
        unset($dompdf, $pdf, $canvas, $report);
        gc_collect_cycles();

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
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

    public function archive(Request $request, EmergencyReport $report, ArchiveService $archives)
    {
        Gate::authorize('update', $report);

        $archives->archive($report, $request->user());

        return redirect()->route('admin.reports.index')->with('success', 'Report moved to Archives.');
    }
}
