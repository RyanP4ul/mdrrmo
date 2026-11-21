<?php

namespace Tests\Feature;

use App\Events\EmergencyReportUpdated;
use App\Events\ServiceScheduleUpdated;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncidentReportBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function incidentType(): IncidentType
    {
        return IncidentType::firstOrCreate(['name' => 'Fire'], [
            'description' => 'Fire emergency',
            'priority' => 'critical',
            'color' => '#dc2626',
        ]);
    }

    public function test_web_resident_report_submission_broadcasts_to_dispatch_channel(): void
    {
        Event::fake([EmergencyReportUpdated::class]);

        $type = $this->incidentType();
        $resident = ResidentUser::factory()->create(['registration_status' => 'accepted']);

        $this->actingAs($resident)
            ->post('/resident/report', [
                'incident_type_id' => $type->id,
                'reporter_type' => 'victim',
                'location' => 'Barangay Poblacion',
                'lat' => 14.8153,
                'lng' => 120.9094,
                'description' => 'House fire near the market.',
            ])
            ->assertRedirect(route('resident.history'));

        Event::assertDispatched(EmergencyReportUpdated::class, function (EmergencyReportUpdated $event): bool {
            $channels = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            $payload = $event->broadcastWith();

            return $event->created === true
                && $event->broadcastAs() === 'report.updated'
                && in_array('private-dispatch', $channels, true)
                && $payload['created'] === true
                && $payload['status'] === 'pending'
                && $payload['incident_type'] === 'Fire'
                && $payload['location'] === 'Barangay Poblacion';
        });
    }

    public function test_mobile_resident_report_submission_broadcasts_to_dispatch_channel(): void
    {
        Event::fake([EmergencyReportUpdated::class]);

        $type = $this->incidentType();
        $resident = ResidentUser::factory()->create(['registration_status' => 'accepted']);
        Sanctum::actingAs($resident, ['*']);

        $this->postJson('/api/resident/reports', [
            'incident_type_id' => $type->id,
            'reporter_type' => 'witness',
            'location' => 'San Juan Road',
            'lat' => 14.8200,
            'lng' => 120.9100,
            'description' => 'Smoke visible from warehouse.',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        Event::assertDispatched(EmergencyReportUpdated::class, function (EmergencyReportUpdated $event): bool {
            $channels = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            $payload = $event->broadcastWith();

            return $event->created === true
                && in_array('private-dispatch', $channels, true)
                && $payload['created'] === true
                && $payload['status'] === 'pending';
        });
    }

    public function test_guest_report_submission_broadcasts_to_dispatch_channel(): void
    {
        Event::fake([EmergencyReportUpdated::class]);
        Storage::fake('r2');

        $type = $this->incidentType();

        $this->postJson('/api/guest/reports', [
            'incident_type_id' => $type->id,
            'reporter_type' => 'witness',
            'reporter_name' => 'Juan Dela Cruz',
            'reporter_contact' => '09171234567',
            'location' => 'Municipal Plaza',
            'lat' => 14.8153,
            'lng' => 120.9094,
            'description' => 'Fire reported near plaza.',
            'photo' => UploadedFile::fake()->image('incident.png'),
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        Event::assertDispatched(EmergencyReportUpdated::class, function (EmergencyReportUpdated $event): bool {
            $channels = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            $payload = $event->broadcastWith();

            return $event->created === true
                && in_array('private-dispatch', $channels, true)
                && $payload['created'] === true
                && $payload['status'] === 'pending';
        });
    }

    public function test_web_resident_schedule_request_broadcasts_to_dispatch_channel(): void
    {
        Event::fake([ServiceScheduleUpdated::class]);

        $resident = ResidentUser::factory()->create(['registration_status' => 'accepted']);
        $future = now(config('mobile.timezone'))->addDays(2);

        $this->actingAs($resident)
            ->post('/resident/schedule', [
                'service_type' => 'Ambulance Standby',
                'preferred_date' => $future->format('Y-m-d'),
                'preferred_time' => '09:30',
                'address' => 'Barangay Hall, Balagtas',
                'notes' => 'Community event standby',
            ])
            ->assertRedirect();

        Event::assertDispatched(ServiceScheduleUpdated::class, function (ServiceScheduleUpdated $event): bool {
            $channels = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            $payload = $event->broadcastWith();

            return $event->created === true
                && $event->broadcastAs() === 'schedule.updated'
                && in_array('private-dispatch', $channels, true)
                && $payload['created'] === true
                && $payload['status'] === 'pending'
                && $payload['service_type'] === 'Ambulance Standby'
                && $payload['address'] === 'Barangay Hall, Balagtas';
        });
    }

    public function test_web_resident_can_withdraw_pending_report(): void
    {
        Event::fake([EmergencyReportUpdated::class]);

        $type = $this->incidentType();
        $resident = ResidentUser::factory()->create(['registration_status' => 'accepted']);
        $report = EmergencyReport::create([
            'reference' => 'RPT-TEST-WD',
            'incident_type_id' => $type->id,
            'reported_by' => $resident->id,
            'reported_by_type' => $resident->getMorphClass(),
            'reporter_name' => $resident->full_name,
            'reporter_contact' => $resident->contact_number,
            'reporter_type' => 'victim',
            'location' => 'Barangay Poblacion',
            'lat' => 14.8153,
            'lng' => 120.9094,
            'description' => 'False alarm.',
            'status' => 'pending',
            'priority' => 'critical',
            'source' => 'mobile-app',
            'reported_at' => now(),
        ]);

        $this->actingAs($resident)
            ->patch("/resident/report/{$report->id}/withdraw", ['reason' => 'False alarm'])
            ->assertRedirect();

        $report->refresh();
        $this->assertNotNull($report->withdrawn_at);
        $this->assertSame('False alarm', $report->withdrawal_reason);
        $this->assertSame('invalid', $report->status);

        Event::assertDispatched(EmergencyReportUpdated::class, fn ($event) => $event->report->id === $report->id);
    }
}
