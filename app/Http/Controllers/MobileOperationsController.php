<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Services\AutomaticDispatch;
use App\Services\DispatchService;
use App\Services\EmergencyReportWorkflow;
use App\Services\ServiceScheduleWorkflow;
use App\Support\Audit;
use App\Support\MobilePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MobileOperationsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        return response()->json(['role' => $request->user()->role, 'name' => $request->user()->full_name,
            'pending_reports' => EmergencyReport::where('status', 'pending')->count(),
            'active_dispatches' => EmergencyReport::where('status', 'dispatched')->count(),
            'pending_schedules' => ServiceSchedule::where('status', 'pending')->count(),
            'pending_residents' => ResidentUser::where('registration_status', 'pending')->count(),
            'active_responders' => DriverResponderUser::where('status', 'active')->count(),
            'automatic_dispatch' => (bool) DB::table('automatic_dispatch_settings')->where('id', 1)->value('enabled'),
            'dispatch_policy' => 'Untried reports are prioritized critical, high, medium, then low; oldest first. Waiting reports are retried by oldest attempt so an unmatched backlog cannot block new reports. Requires an active capable team, an on-shift free driver/responder. Capabilities must include the exact incident name or * for all incidents. Waiting reports are retried every minute by the scheduler.',
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,acknowledged,dispatched,resolved,invalid,withdrawn']]);
        $query = EmergencyReport::with(['incidentType', 'team', 'driver']);
        if (($data['status'] ?? null) === 'withdrawn') {
            $query->whereNotNull('withdrawn_at');
        } elseif (! empty($data['status'])) {
            $query->where('status', $data['status'])->whereNull('withdrawn_at');
        }

        return response()->json(MobilePage::make($query, fn ($report) => $this->reportData($report), $request));
    }

    public function report(EmergencyReport $report): JsonResponse
    {
        return response()->json($this->reportData($report) + ['patient_form' => $report->form]);
    }

    private function reportData(EmergencyReport $report): array
    {
        $report->loadMissing(['incidentType', 'team', 'driver']);

        return array_replace($report->toArray(), ['status' => $report->withdrawn_at ? 'withdrawn' : $report->status]) + ['type' => $report->incidentType?->name, 'team_name' => $report->team?->team_name, 'driver_name' => $report->driver?->full_name,
            'automatic_dispatch' => DB::table('automatic_dispatch_attempts')->where('emergency_report_id', $report->id)->first()];
    }

    public function updateReport(Request $request, EmergencyReport $report, EmergencyReportWorkflow $workflow): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:acknowledged,invalid,resolved'], 'priority' => ['nullable', 'in:low,medium,high,critical']]);
        abort_if($data['status'] === 'resolved' && ! $request->user()->hasRole('admin'), 403, 'Only an administrator can close a dispatch here.');

        return response()->json($this->reportData($workflow->transition($report, $data['status'], $request->user(), $data['priority'] ?? null)));
    }

    public function assign(Request $request, EmergencyReport $report, DispatchService $dispatch): JsonResponse
    {
        $data = $request->validate(['team_id' => ['required', 'integer', 'exists:response_teams,id']]);

        return response()->json($this->reportData($dispatch->dispatch($report, ResponseTeam::findOrFail($data['team_id']))));
    }

    public function schedules(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,acknowledged,confirmed,declined,completed,cancelled,en-route,on-scene']]);

        return response()->json(MobilePage::make(ServiceSchedule::with('resident:id,first_name,last_name,contact_number')->withCount('responders')->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status)), request: $request));
    }

    public function reviewSchedule(Request $request, ServiceSchedule $schedule, ServiceScheduleWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:acknowledged,confirmed,declined,completed'],
            'team_id' => ['required_if:decision,confirmed', 'nullable', 'integer', 'exists:response_teams,id'],
            'reason' => ['required_if:decision,declined', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json($workflow->review($schedule, $request->user(), $data['decision'], $data['team_id'] ?? null, $data['reason'] ?? null));
    }

    public function automatic(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'current_password' => ['required', 'string']]);
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'The current password is incorrect.');
        DB::table('automatic_dispatch_settings')->where('id', 1)->update(['enabled' => $data['enabled'], 'updated_at' => now()]);
        Audit::record('AUTOMATIC_DISPATCH_POLICY', $data['enabled'] ? 'Enabled automatic dispatch' : 'Disabled automatic dispatch');

        return response()->json(['enabled' => $data['enabled']]);
    }

    public function retryAutomatic(AutomaticDispatch $dispatch): JsonResponse
    {
        return response()->json(['dispatched' => $dispatch->drain()]);
    }

    public function audits(Request $request): JsonResponse
    {
        return response()->json(MobilePage::make(AuditLog::query(), request: $request));
    }
}
