<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Resident\ResidentController;
use App\Http\Requests\SavePatientReportRequest;
use App\Models\DispatchTrip;
use App\Models\DriverNotification;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ReportForm;
use App\Models\ServiceSchedule;
use App\Services\EmergencyReportWorkflow;
use App\Services\ServiceScheduleWorkflow;
use App\Support\Audit;
use App\Support\MobilePage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MobileRecordsController extends Controller
{
    private function ownedReport(Request $request, int $id, bool $lock = false): EmergencyReport
    {
        $user = $request->user();

        return EmergencyReport::query()->whereKey($id)
            ->where(function ($query) use ($user): void {
                $query->where(fn ($owner) => $owner->where('reported_by', $user->getKey())->where('reported_by_type', $user->getMorphClass()));
                if ($user->hasRole('driver/responder')) {
                    $query->orWhere('assigned_driver_id', $user->getKey())
                        ->orWhereHas('team', fn ($team) => $team
                            ->where('status', 'active')
                            ->whereHas('members', fn ($member) => $member
                                ->where('user_id', $user->getKey())
                                ->where('status', 'active')));
                }
            })->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public function report(Request $request, int $report): JsonResponse
    {
        return response()->json($this->reportData($request, $this->ownedReport($request, $report)));
    }

    private function reportData(Request $request, EmergencyReport $report): array
    {
        $report->load(['incidentType', 'driver', 'team.vehicles', 'team.members.user', 'form']);
        $canCare = $request->user()->hasRole('driver/responder') && Gate::allows('updateAsDriver', $report);
        $canEditTrip = $request->user()->hasRole('driver/responder') && Gate::allows('editTripAsDriver', $report);
        $trip = DispatchTrip::where('emergency_report_id', $report->id)->first();
        $authorizedPassenger = $this->resolveAuthorizedPassenger($report);

        return [
            'id' => $report->id, 'reference' => $report->reference,
            'incident_type_id' => $report->incident_type_id, 'type' => $report->incidentType?->name,
            'status' => $report->withdrawn_at ? 'withdrawn' : $report->status,
            'location' => $report->location, 'description' => $report->description,
            'lat' => $report->lat, 'lng' => $report->lng, 'priority' => $report->priority,
            'reporter_type' => $report->reporter_type,
            'submitted_patient' => $request->user()->hasRole('driver/responder') ? $report->victim_info : null,
            'driver' => $report->driver?->full_name, 'team' => $report->team?->team_name,
            'vehicle' => $report->team?->vehicles->firstWhere('assigned_report_id', $report->id)?->plate_number ?? $trip?->vehicle_number,
            'authorized_passenger' => $request->user()->hasRole('driver/responder') ? $authorizedPassenger : null,
            'driver_form' => $canCare ? ($report->form?->driver_section ?: null) : null,
            'reported_at' => $report->reported_at?->toIso8601String(),
            'acknowledged_at' => $report->acknowledged_at?->toIso8601String(),
            'resolved_at' => $report->resolved_at?->toIso8601String(),
            'withdrawn_at' => $report->withdrawn_at, 'withdrawal_reason' => $report->withdrawal_reason,
            'revision' => $this->revision($report),
            'can_edit' => $request->user()->hasRole('resident') && $report->status === 'pending',
            'can_care' => $canCare,
            'can_fill_patient_form' => $canCare,
            'can_edit_trip' => $canEditTrip,
            'trip' => $canCare ? $trip : null,
        ];
    }

    private function resolveAuthorizedPassenger(EmergencyReport $report): string
    {
        $existing = trim((string) ($report->form?->driver_section['authorizedPassenger'] ?? $report->form?->driver_section['passengerName'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }
        $patientName = trim((string) (($report->victim_info['name'] ?? null) ?: $report->reporter_name ?: ''));
        $responders = $report->team?->members
            ? $report->team->members
                ->where('status', 'active')
                ->filter(fn ($m) => ($m->role ?? '') !== 'Driver' && $m->user_id !== $report->assigned_driver_id)
                ->map(fn ($m) => trim((string) ($m->user?->full_name ?? $m->name)))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];

        return collect([$patientName, ...$responders])->filter()->unique()->implode(', ');
    }

    public function updateReport(Request $request, int $report): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'string'],
            'incident_type_id' => ['required', 'integer', 'exists:incident_types,id'],
            'reporter_type' => ['required', 'in:victim,witness'],
            'location' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return DB::transaction(function () use ($request, $report, $data): JsonResponse {
            $record = $this->ownedReport($request, $report, true);
            abort_unless($record->status === 'pending', 422, 'Only pending reports can be edited.');
            $this->checkRevision($record, $data['revision']);
            unset($data['revision']);
            $data['priority'] = IncidentType::findOrFail($data['incident_type_id'])->priority;
            $record->update($data);
            Audit::record('UPDATE_REPORT', "Resident edited {$record->reference}");

            return response()->json($this->reportData($request, $record->refresh()));
        });
    }

    public function withdraw(Request $request, int $report, EmergencyReportWorkflow $workflow): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return DB::transaction(function () use ($request, $report, $workflow, $data): JsonResponse {
            $record = $this->ownedReport($request, $report, true);
            if ($record->withdrawn_at) {
                return response()->json($this->reportData($request, $record));
            }
            abort_unless($record->status === 'pending', 422, 'Only pending reports can be withdrawn.');
            // Legacy dispatch systems use invalid as the terminal exclusion state.
            // Explicit withdrawal metadata distinguishes it from a dispatcher rejection.
            $record->update(['withdrawn_at' => now(), 'withdrawal_reason' => $data['reason']]);
            $record = $workflow->transition($record, 'invalid', $request->user());

            return response()->json($this->reportData($request, $record));
        });
    }

    public function schedule(Request $request, int $schedule): JsonResponse
    {
        $record = $request->user()->schedules()->with('team:id,team_name')->findOrFail($schedule);

        return response()->json(array_replace($record->toArray(), ['preferred_time' => Carbon::parse($record->preferred_time)->format('H:i')]) + ['team_name' => $record->team?->team_name, 'can_edit' => $record->status === 'pending', 'revision' => $this->revision($record)]);
    }

    public function driverSchedule(Request $request, int $schedule): JsonResponse
    {
        $record = ServiceSchedule::query()
            ->whereHas('responders', fn ($query) => $query->whereKey($request->user()->id))
            ->with(['resident:id,first_name,last_name,contact_number', 'team:id,team_name'])
            ->findOrFail($schedule);

        return response()->json($this->assignedScheduleData($record));
    }

    private function assignedScheduleData(ServiceSchedule $record): array
    {
        return [
            'id' => $record->getKey(),
            'service_type' => $record->service_type,
            'status' => $record->status,
            'address' => $record->address,
            'preferred_date' => $record->preferred_date->toDateString(),
            'preferred_time' => Carbon::parse($record->preferred_time)->format('H:i'),
            'notes' => $record->notes,
            'resident' => $record->resident->full_name,
            'contact' => $record->resident->contact_number,
            'team_name' => $record->team?->team_name,
            'started_at' => $record->started_at, 'arrived_at' => $record->arrived_at, 'completed_at' => $record->completed_at,
        ];
    }

    public function driverSchedules(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'today' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        return response()->json(MobilePage::make(
            ServiceSchedule::query()
                ->with(['resident:id,first_name,last_name,contact_number', 'team:id,team_name'])
                ->whereHas('responders', fn ($query) => $query->whereKey($request->user()->id))
                ->when($filters['active'] ?? false, fn ($query) => $query->whereIn('status', ServiceSchedule::ACTIVE_STATUSES))
                ->when($filters['today'] ?? false, fn ($query) => $query
                    ->whereDate('preferred_date', Carbon::today(config('mobile.timezone'))->toDateString())
                    ->whereIn('status', ServiceSchedule::ACTIVE_STATUSES)),
            fn (ServiceSchedule $record): array => $this->assignedScheduleData($record),
            $request,
        ));
    }

    public function completeDriverSchedule(Request $request, int $schedule, ServiceScheduleWorkflow $workflow): JsonResponse
    {
        $record = $workflow->progress(ServiceSchedule::findOrFail($schedule), $request->user(), 'completed');

        return response()->json(['id' => $record->id, 'status' => $record->status]);
    }

    public function progressDriverSchedule(Request $request, int $schedule, ServiceScheduleWorkflow $workflow): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:en-route,on-scene,completed']]);
        $record = $workflow->progress(ServiceSchedule::findOrFail($schedule), $request->user(), $data['status']);

        return response()->json(['id' => $record->id, 'status' => $record->status]);
    }

    public function updateSchedule(Request $request, int $schedule, ServiceScheduleWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'string'],
            'service_type' => ['required', 'in:'.implode(',', ResidentController::SERVICES)],
            'address' => ['required', 'string', 'max:255'],
            'preferred_date' => ['required', 'date_format:Y-m-d'],
            'preferred_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if (Carbon::parse($data['preferred_date'].' '.$data['preferred_time'], config('mobile.timezone'))->isPast()) {
            throw ValidationException::withMessages(['preferred_time' => 'Choose a future date and time.']);
        }

        return DB::transaction(function () use ($request, $schedule, $data, $workflow): JsonResponse {
            $record = $request->user()->schedules()->whereKey($schedule)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'pending', 422, 'Only pending requests can be edited.');
            $this->checkRevision($record, $data['revision']);
            unset($data['revision']);
            $workflow->validateResidentSlot($request->user(), $data, $record->id);
            $record->update($data);
            Audit::record('UPDATE_SCHEDULE', "Resident edited schedule {$record->id}");

            return $this->schedule($request, $schedule);
        });
    }

    private function revision(Model $record): string
    {
        $attributes = $record->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    private function checkRevision(Model $record, string $revision): void
    {
        abort_unless($this->revision($record) === $revision, 409, 'This record changed. Reload it before saving.');
    }

    public function authored(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(MobilePage::make(EmergencyReport::query()->where('reported_by', $user->getKey())->where('reported_by_type', $user->getMorphClass()), fn ($report) => $this->reportData($request, $report), $request));
    }

    public function patient(Request $request, EmergencyReport $report): JsonResponse
    {
        abort_unless(Gate::allows('updateAsDriver', $report), 404);
        $report->loadMissing(['incidentType', 'driver', 'team.vehicles', 'team.members.user', 'trip', 'form']);
        $teamVehicle = $report->team?->vehicles->firstWhere('assigned_report_id', $report->id)
            ?? $report->team?->resolveActiveVehicle();

        return response()->json([
            'form' => $report->form,
            'revision' => $report->form ? $this->revision($report->form) : 'new',
            'type' => $report->incidentType?->name,
            'defaults' => [
                'tripDate' => ($report->reported_at ?? now())->toDateString(),
                'driverName' => $report->driver?->full_name ?? $request->user()?->full_name,
                'governmentCardPlateNo' => $teamVehicle?->plate_number ?? $report->trip?->vehicle_number,
                'authorizedPassenger' => $this->resolveAuthorizedPassenger($report),
                'placeVisitedInspected' => $report->trip?->destination ?: $report->location,
                'purpose' => 'Emergency Response - '.($report->incidentType?->name ?? 'Emergency'),
            ],
        ]);
    }

    public function savePatient(SavePatientReportRequest $request, EmergencyReport $report): JsonResponse
    {
        return DB::transaction(function () use ($request, $report): JsonResponse {
            $record = EmergencyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('updateAsDriver', $record);
            $revision = $request->validate(['revision' => ['required', 'string']])['revision'];
            $existing = $record->form;
            abort_unless($revision === ($existing ? $this->revision($existing) : 'new'), 409, 'The patient form changed. Reload it before saving.');
            $validated = $request->safe()->only(['driver_section', 'emergency_section']);
            $driverSection = array_key_exists('driver_section', $validated) && is_array($validated['driver_section'])
                ? array_merge($existing?->driver_section ?? [], $validated['driver_section'])
                : ($existing?->driver_section ?? []);
            $emergencySection = array_key_exists('emergency_section', $validated) && is_array($validated['emergency_section'])
                ? array_merge($existing?->emergency_section ?? [], $validated['emergency_section'])
                : ($existing?->emergency_section ?? []);
            $form = ReportForm::updateOrCreate(
                ['emergency_report_id' => $record->id],
                [
                    'driver_section' => $driverSection,
                    'emergency_section' => $emergencySection,
                ],
            );
            Audit::record('UPDATE_PATIENT_REPORT', "Driver saved patient form {$record->reference}");

            return response()->json(['form' => $form, 'revision' => $this->revision($form)]);
        });
    }

    public function saveTrip(Request $request, EmergencyReport $report): JsonResponse
    {
        $data = $request->validate([
            'destination' => ['required', 'string', 'max:255'],
            'picked_up_at' => ['nullable', 'date', 'before_or_equal:now'],
            'arrived_at' => ['nullable', 'date', 'before_or_equal:now', 'after_or_equal:picked_up_at', 'required_with:distance_km'],
            'distance_km' => ['nullable', 'numeric', 'between:0,20000'],
        ]);
        if (! empty($data['arrived_at']) && empty($data['picked_up_at'])) {
            throw ValidationException::withMessages(['picked_up_at' => 'Record pickup before arrival.']);
        }
        if (! empty($data['picked_up_at']) && Carbon::parse($data['picked_up_at'])->lt($report->reported_at)) {
            throw ValidationException::withMessages(['picked_up_at' => 'Pickup cannot precede the report.']);
        }

        return DB::transaction(function () use ($request, $report, $data): JsonResponse {
            $record = EmergencyReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
            abort_unless(Gate::allows('updateAsDriver', $record), 404);
            Gate::authorize('editTripAsDriver', $record);
            foreach (['picked_up_at', 'arrived_at'] as $field) {
                $data[$field] = empty($data[$field]) ? null : Carbon::parse($data[$field])->utc();
            }
            DB::table('dispatch_trips')->updateOrInsert(['emergency_report_id' => $report->id], $data + ['updated_at' => now()]);
            Audit::record('UPDATE_TRIP', "Driver saved trip {$record->reference}; distance is driver reported");

            return response()->json($this->reportData($request, $record));
        });
    }

    public function inbox(Request $request): JsonResponse
    {
        return response()->json(MobilePage::make(DriverNotification::query()->where('driver_id', $request->user()->getKey()), request: $request));
    }

    public function readInbox(Request $request, int $notification): JsonResponse
    {
        $record = DB::table('driver_notifications')->where('driver_id', $request->user()->getKey())->where('id', $notification)->first();
        abort_unless($record, 404);
        DB::table('driver_notifications')->where('id', $record->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Notification marked as read.']);
    }
}
