<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Resident\ResidentController;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MobileResidentApiController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        /** @var ResidentUser $resident */
        $resident = $request->user();

        return response()->json([
            'profile' => [
                'id' => $resident->getKey(),
                'name' => $resident->full_name,
                'contact' => $resident->contact_number ?? '',
                'address' => collect([
                    $resident->house_no,
                    $resident->street,
                    $resident->barangay,
                    $resident->city,
                    $resident->province,
                ])->filter()->implode(', '),
                'email' => $resident->email,
            ],
            'incident_types' => IncidentType::query()
                ->orderBy('name')
                ->get(['id', 'name', 'priority', 'color']),
            'reports' => $resident->reports()
                ->with('incidentType:id,name,color')
                ->latest('reported_at')
                ->limit(50)
                ->get()
                ->map(fn (EmergencyReport $report): array => $this->reportData($report)),
            'schedules' => $resident->schedules()
                ->latest()
                ->limit(50)
                ->get(['id', 'service_type', 'preferred_date', 'preferred_time', 'address', 'notes', 'status', 'created_at']),
            'notifications' => ResidentNotification::query()
                ->where('user_id', $resident->getKey())
                ->latest()
                ->limit(50)
                ->get(['id', 'type', 'title', 'message', 'read_at', 'created_at']),
        ]);
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
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        /** @var ResidentUser $resident */
        $resident = $request->user();

        $data = $request->validate([
            'service_type' => ['required', 'string', 'in:'.implode(',', ResidentController::SERVICES)],
            'preferred_date' => ['required', 'date', 'after_or_equal:today'],
            'preferred_time' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

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
            'status' => $report->status,
            'priority' => $report->priority,
            'lat' => $report->lat,
            'lng' => $report->lng,
            'reported_at' => $report->reported_at?->toIso8601String(),
        ];
    }
}
