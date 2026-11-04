<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Resident\ResidentController;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\ServiceSchedule;
use App\Support\MobilePage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileResidentApiController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        /** @var ResidentUser $resident */
        $resident = $request->user();

        $reports = MobilePage::make($resident->reports()->with('incidentType:id,name,color'), fn (EmergencyReport $report): array => $this->reportData($report));
        $schedules = MobilePage::make($resident->schedules());
        $notifications = MobilePage::make(ResidentNotification::query()->where('user_id', $resident->getKey()));

        return response()->json([
            'profile' => [
                'id' => $resident->getKey(), 'name' => $resident->full_name,
                'contact' => $resident->contact_number ?? '',
                'address' => collect([$resident->house_no, $resident->street, $resident->barangay, $resident->city, $resident->province])->filter()->implode(', '),
                'email' => $resident->email, 'registration_status' => $resident->registration_status,
            ],
            'incident_types' => IncidentType::query()->orderBy('name')->get(['id', 'name', 'priority', 'color']),
            'reports' => $reports['data'], 'schedules' => $schedules['data'], 'notifications' => $notifications['data'],
            'paging' => ['reports' => $reports['next_before'], 'schedules' => $schedules['next_before'], 'notifications' => $notifications['next_before']],
            'unread_count' => ResidentNotification::query()->where('user_id', $resident->getKey())->whereNull('read_at')->count(),
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        return response()->json(MobilePage::make($request->user()->reports()->with('incidentType:id,name,color'), fn (EmergencyReport $report): array => $this->reportData($report), $request));
    }

    public function schedules(Request $request): JsonResponse
    {
        return response()->json(MobilePage::make($request->user()->schedules(), request: $request));
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json(MobilePage::make(ResidentNotification::query()->where('user_id', $request->user()->getKey()), request: $request));
    }

    public function storeReport(Request $request): JsonResponse
    {
        /** @var ResidentUser $resident */
        $resident = $request->user();
        abort_unless($resident->registration_status === 'accepted', 403, 'Your account is awaiting approval.');

        $data = $request->validate([
            'incident_type_id' => ['required', 'integer', 'exists:incident_types,id'],
            'reporter_type' => ['required', 'in:victim,witness'],
            'location' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($resident, $data): JsonResponse {
            $incidentType = IncidentType::query()->findOrFail($data['incident_type_id']);
            $report = $resident->reports()->create($data + [
                'reference' => 'RPT-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
                'status' => 'pending',
                'priority' => $incidentType->priority,
                'source' => 'mobile-app',
                'reported_by' => $resident->getKey(),
                'reported_by_type' => $resident->getMorphClass(),
                'reporter_name' => $resident->full_name,
                'reporter_contact' => $resident->contact_number,
                'reported_at' => now(),
            ]);
            $notification = ResidentNotification::query()->create([
                'user_id' => $resident->getKey(),
                'type' => 'report_update',
                'title' => 'Report received',
                'message' => "Your report {$report->reference} has been received by MDRRMO.",
                'related_type' => EmergencyReport::class,
                'related_id' => $report->getKey(),
            ]);
            $report->load('incidentType:id,name,color');

            return response()->json([
                'report' => $this->reportData($report),
                'notification' => $notification,
            ], 201);
        });
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        /** @var ResidentUser $resident */
        $resident = $request->user();
        abort_unless($resident->registration_status === 'accepted', 403, 'Your account is awaiting approval.');

        $data = $request->validate([
            'service_type' => ['required', 'string', 'in:'.implode(',', ResidentController::SERVICES)],
            'preferred_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now(config('mobile.timezone'))->toDateString()],
            'preferred_time' => ['required', 'date_format:H:i,g:i A'],
            'address' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $time = Carbon::createFromFormat(str_contains($data['preferred_time'], 'M') ? 'g:i A' : 'H:i', $data['preferred_time'])->format('H:i');
        $when = Carbon::parse($data['preferred_date'].' '.$time, config('mobile.timezone'));
        if ($when->isPast()) {
            throw ValidationException::withMessages(['preferred_time' => 'Choose a future date and time.']);
        }
        $data['preferred_time'] = $time;

        return DB::transaction(function () use ($resident, $data): JsonResponse {
            $schedule = $resident->schedules()->create($data + ['status' => 'pending']);
            $notification = ResidentNotification::query()->create([
                'user_id' => $resident->getKey(),
                'type' => 'general',
                'title' => 'Schedule request received',
                'message' => "Your {$schedule->service_type} request has been received by MDRRMO.",
                'related_type' => $schedule::class,
                'related_id' => $schedule->getKey(),
            ]);

            return response()->json([
                'schedule' => $schedule,
                'notification' => $notification,
            ], 201);
        });
    }

    public function cancelSchedule(Request $request, int $schedule): JsonResponse
    {
        return DB::transaction(function () use ($request, $schedule): JsonResponse {
            $record = $request->user()->schedules()->whereKey($schedule)->lockForUpdate()->firstOrFail();
            if ($record->status === 'cancelled') {
                return response()->json(['schedule' => $record]);
            }
            Gate::authorize('cancel', $record);
            $record->update(['status' => 'cancelled']);
            $notification = ResidentNotification::query()->create([
                'user_id' => $request->user()->getKey(),
                'type' => 'schedule_cancelled',
                'title' => 'Schedule cancelled',
                'message' => "Your {$record->service_type} request has been cancelled.",
                'related_type' => ServiceSchedule::class,
                'related_id' => $record->getKey(),
            ]);

            return response()->json(['schedule' => $record, 'notification' => $notification]);
        });
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        ResidentNotification::query()
            ->where('user_id', $request->user()->getKey())
            ->whereKey($notification)
            ->firstOrFail()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        ResidentNotification::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportData(EmergencyReport $report): array
    {
        return [
            'id' => $report->getKey(),
            'reference' => $report->reference,
            'incident_type_id' => $report->incident_type_id,
            'type' => $report->incidentType?->name ?? 'Unknown',
            'location' => $report->location,
            'status' => $report->withdrawn_at ? 'withdrawn' : $report->status,
            'priority' => $report->priority,
            'lat' => $report->lat,
            'lng' => $report->lng,
            'reported_at' => $report->reported_at?->toIso8601String(),
        ];
    }
}
