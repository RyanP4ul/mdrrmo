<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateEmergencyOperationsReportRequest;
use App\Http\Requests\UpdateEmergencyReportRequest;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Services\EmergencyOperationsReportService;
use App\Services\EmergencyReportWorkflow;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $timezone = (string) config('reporting.timezone', 'Asia/Manila');
        $nowManila = CarbonImmutable::now($timezone);

        return Inertia::render('Admin/Reports/Index', [
            'filters' => $filters,
            'types' => IncidentType::orderBy('name')->get(['id', 'name']),
            'reports' => EmergencyReport::with(['incidentType:id,name,color', 'form', 'driver:id,first_name,last_name'])
                ->when(($filters['tab'] ?? '') === 'driver', fn ($query) => $query->withDriverForm())
                ->filter($filters)
                ->latest('reported_at')
                ->paginate(8)->withQueryString(),
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
}
