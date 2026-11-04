<?php

namespace Tests\Feature;

use App\Events\EmergencyReportUpdated;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_report_is_persisted_and_only_visible_to_its_owner(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $otherResident = ResidentUser::factory()->accepted()->create();
        $incidentType = $this->createIncidentType();

        Sanctum::actingAs($resident, ['*']);
        $response = $this->mobilePostJson('/api/resident/reports', [
            'incident_type_id' => $incidentType->getKey(),
            'reporter_type' => 'victim',
            'location' => '12 Main Street, Balagtas',
            'lat' => 14.8153,
            'lng' => 120.9094,
            'description' => 'Flood water is entering the house.',
        ])->assertCreated()
            ->assertJsonPath('report.status', 'pending')
            ->assertJsonPath('notification.type', 'report_update');

        $reportId = $response->json('report.id');
        $this->assertDatabaseHas('emergency_reports', [
            'id' => $reportId,
            'reported_by' => $resident->getKey(),
            'reported_by_type' => $resident->getMorphClass(),
            'incident_type_id' => $incidentType->getKey(),
        ]);
        $this->assertDatabaseHas('resident_notifications', [
            'user_id' => $resident->getKey(),
            'related_id' => $reportId,
        ]);

        Sanctum::actingAs($otherResident, ['*']);
        $this->getJson('/api/resident/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'reports')
            ->assertJsonCount(0, 'notifications');
    }

    public function test_resident_schedule_requests_are_persisted_with_a_notification(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        Sanctum::actingAs($resident, ['*']);

        $this->mobilePostJson('/api/resident/schedules', [
            'service_type' => 'Medical Mission',
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time' => '10:30 AM',
            'address' => 'Barangay hall',
        ])->assertCreated()
            ->assertJsonPath('schedule.status', 'pending')
            ->assertJsonPath('notification.title', 'Schedule request received');

        $this->assertDatabaseHas('service_schedules', [
            'resident_id' => $resident->getKey(),
            'service_type' => 'Medical Mission',
            'address' => 'Barangay hall',
        ]);
        $this->assertDatabaseHas('resident_notifications', [
            'user_id' => $resident->getKey(),
            'type' => 'general',
        ]);
    }

    public function test_driver_assignment_status_updates_are_persisted_through_completion(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::query()->create(['team_name' => 'Alpha']);
        TeamMember::query()->create([
            'response_team_id' => $team->getKey(),
            'user_id' => $driver->getKey(),
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'response_team_id' => $team->getKey(),
            'vehicle_type' => 'ambulance',
            'plate_number' => 'TEST-001',
            'status' => 'available',
        ]);
        $incidentType = $this->createIncidentType();
        $report = EmergencyReport::query()->create([
            'reference' => 'RPT-TEST-0001',
            'incident_type_id' => $incidentType->getKey(),
            'location' => '12 Main Street, Balagtas',
            'lat' => 14.8153,
            'lng' => 120.9094,
            'description' => 'Medical emergency',
            'status' => 'dispatched',
            'priority' => 'high',
            'source' => 'mobile-app',
            'reporter_type' => 'victim',
            'reported_by' => $driver->getKey(),
            'reported_by_type' => $driver->getMorphClass(),
            'reporter_name' => $driver->full_name,
            'assigned_driver_id' => $driver->getKey(),
            'response_team_id' => $team->getKey(),
            'reported_at' => now(),
        ]);

        Sanctum::actingAs($driver, ['*']);
        $this->patchJson("/api/driver-responder/assignments/{$report->getKey()}/status", [
            'vehicle_status' => 'en-route',
        ])->assertOk()->assertJsonPath('assignment.response_status', 'en-route');

        $this->patchJson("/api/driver-responder/assignments/{$report->getKey()}/status", [
            'vehicle_status' => 'on-scene',
        ])->assertOk()->assertJsonPath('assignment.response_status', 'on-scene');

        $this->patchJson("/api/driver-responder/assignments/{$report->getKey()}/status", [
            'vehicle_status' => 'available',
        ])->assertOk()->assertJsonPath('assignment', null);

        $this->assertDatabaseHas('emergency_reports', [
            'id' => $report->getKey(),
            'status' => 'resolved',
        ]);
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->getKey(),
            'status' => 'available',
            'assigned_report_id' => null,
        ]);
        $this->getJson('/api/driver-responder/history')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'resolved');
    }

    public function test_driver_profile_is_saved_and_emergency_report_waits_for_dispatch_review(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::query()->create(['team_name' => 'Bravo']);
        TeamMember::query()->create([
            'response_team_id' => $team->getKey(),
            'user_id' => $driver->getKey(),
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'response_team_id' => $team->getKey(),
            'vehicle_type' => 'ambulance',
            'plate_number' => 'TEST-002',
            'status' => 'available',
        ]);
        $incidentType = $this->createIncidentType();

        Sanctum::actingAs($driver, ['*']);
        $this->putJson('/api/driver-responder/profile', [
            'name' => 'Taylor Driver',
            'contact' => '09123456789',
        ])->assertOk()
            ->assertJsonPath('profile.name', 'Taylor Driver')
            ->assertJsonMissingPath('profile.vehicle_type');

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->getKey(),
            'first_name' => 'Taylor',
            'last_name' => 'Driver',
            'contact_number' => '09123456789',
        ]);
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->getKey(),
            'vehicle_type' => 'ambulance',
            'plate_number' => 'TEST-002',
        ]);

        $this->mobilePostJson('/api/driver-responder/reports', [
            'incident_type_id' => $incidentType->getKey(),
            'location' => '12 Main Street, Balagtas',
            'lat' => 14.8153,
            'lng' => 120.9094,
            'priority' => 'critical',
            'patient_name' => 'Jordan Patient',
            'patient_age' => 32,
            'symptoms' => 'Difficulty breathing',
            'contact_person' => 'Taylor Driver',
            'contact_number' => '09123456789',
        ])->assertCreated()
            ->assertJsonPath('report.status', 'pending')
            ->assertJsonPath('report.priority', 'critical');

        $this->assertDatabaseHas('emergency_reports', [
            'incident_type_id' => $incidentType->getKey(),
            'reported_by' => $driver->getKey(),
            'status' => 'pending',
            'assigned_driver_id' => null,
        ]);
    }

    public function test_mobile_registration_creates_a_pending_resident(): void
    {
        $this->mobilePostJson('/api/auth/mobile/register', [
            'name' => 'Taylor Resident',
            'contact' => '09123456789',
            'address' => 'Balagtas, Bulacan',
            'email' => 'taylor@example.test',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
        ])->assertCreated()
            ->assertJsonPath(
                'message',
                'Registration submitted. Your account is waiting for review.',
            );

        $this->assertDatabaseHas('residents', [
            'email' => 'taylor@example.test',
            'registration_status' => 'pending',
        ]);
    }

    public function test_schedule_notes_are_saved_and_owner_can_cancel_with_a_notification(): void
    {
        $this->freezeTime();
        $resident = ResidentUser::factory()->accepted()->create();
        Sanctum::actingAs($resident, ['*']);
        $created = $this->mobilePostJson('/api/resident/schedules', [
            'service_type' => 'Medical Mission', 'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time' => '14:30', 'address' => 'Barangay hall', 'notes' => 'Wheelchair access needed',
        ])->assertCreated()->assertJsonPath('schedule.preferred_time', '14:30');
        $scheduleId = $created->json('schedule.id');

        $this->patchJson("/api/resident/schedules/{$scheduleId}/cancel")
            ->assertOk()->assertJsonPath('schedule.status', 'cancelled')
            ->assertJsonPath('notification.type', 'schedule_cancelled');

        $this->assertDatabaseHas('service_schedules', ['id' => $scheduleId, 'status' => 'cancelled', 'notes' => 'Wheelchair access needed']);
        $this->assertDatabaseHas('resident_notifications', ['user_id' => $resident->getKey(), 'related_id' => $scheduleId, 'type' => 'schedule_cancelled']);
    }

    public function test_schedule_cancellation_returns_404_for_another_resident(): void
    {
        $owner = ResidentUser::factory()->accepted()->create();
        $other = ResidentUser::factory()->accepted()->create();
        $schedule = $owner->schedules()->create(['service_type' => 'Other', 'preferred_date' => '2030-01-01', 'preferred_time' => '09:00', 'address' => 'Town hall']);
        Sanctum::actingAs($other, ['*']);

        $this->patchJson("/api/resident/schedules/{$schedule->id}/cancel")->assertNotFound();

        $this->assertDatabaseHas('service_schedules', ['id' => $schedule->id, 'status' => 'pending']);
        $this->assertDatabaseCount('resident_notifications', 0);
    }

    public function test_schedule_cancellation_returns_403_for_completed_requests(): void
    {
        $owner = ResidentUser::factory()->accepted()->create();
        $schedule = $owner->schedules()->create(['service_type' => 'Other', 'preferred_date' => '2030-01-01', 'preferred_time' => '09:00', 'address' => 'Town hall', 'status' => 'completed']);
        Sanctum::actingAs($owner, ['*']);

        $this->patchJson("/api/resident/schedules/{$schedule->id}/cancel")->assertForbidden();

        $this->assertDatabaseHas('service_schedules', ['id' => $schedule->id, 'status' => 'completed']);
        $this->assertDatabaseCount('resident_notifications', 0);
    }

    public function test_mobile_mutations_return_401_without_authentication(): void
    {
        $this->patchJson('/api/resident/schedules/1/cancel')->assertUnauthorized();
        $this->patchJson('/api/driver-responder/assignments/1/status', ['vehicle_status' => 'available'])->assertUnauthorized();
    }

    public function test_pending_resident_cannot_create_a_schedule(): void
    {
        $resident = ResidentUser::factory()->create(['registration_status' => 'pending']);
        Sanctum::actingAs($resident, ['*']);

        $this->mobilePostJson('/api/resident/schedules', [])->assertForbidden();

        $this->assertDatabaseCount('service_schedules', 0);
    }

    public function test_schedule_rejects_a_past_time_today_and_invalid_clock_values(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00'));
        $resident = ResidentUser::factory()->accepted()->create();
        Sanctum::actingAs($resident, ['*']);
        $payload = ['service_type' => 'Other', 'preferred_date' => '2026-11-03', 'address' => 'Town hall'];

        $this->mobilePostJson('/api/resident/schedules', $payload + ['preferred_time' => '11:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('preferred_time');
        $this->mobilePostJson('/api/resident/schedules', $payload + ['preferred_time' => '25:99'])
            ->assertUnprocessable()->assertJsonValidationErrors('preferred_time');

        $this->assertDatabaseCount('service_schedules', 0);
        $this->assertDatabaseCount('resident_notifications', 0);
    }

    public function test_bootstrap_counts_unread_notifications_beyond_the_loaded_page(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        for ($index = 0; $index < 51; $index++) {
            ResidentNotification::query()->create(['user_id' => $resident->getKey(), 'title' => 'Update', 'message' => 'Status updated', 'type' => 'general']);
        }
        Sanctum::actingAs($resident, ['*']);

        $this->getJson('/api/resident/bootstrap')->assertOk()->assertJsonCount(50, 'notifications')
            ->assertJsonPath('unread_count', 51)->assertJsonPath('profile.registration_status', 'accepted');
    }

    public function test_completion_updates_only_the_assigned_vehicle_and_notifies_the_resident(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::query()->create(['team_name' => 'Multi-vehicle team']);
        TeamMember::query()->create(['response_team_id' => $team->id, 'user_id' => $driver->getKey(), 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);
        $unrelated = Vehicle::query()->create(['response_team_id' => $team->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'UNRELATED', 'status' => 'available']);
        $report = $resident->reports()->create([
            'reference' => 'RPT-COMPLETION', 'incident_type_id' => $this->createIncidentType()->id,
            'location' => 'Town hall', 'lat' => 14.8, 'lng' => 120.9, 'description' => 'Medical emergency',
            'reporter_name' => $resident->full_name, 'assigned_driver_id' => $driver->getKey(),
            'response_team_id' => $team->id, 'status' => 'dispatched',
        ]);
        $assigned = Vehicle::query()->create(['response_team_id' => $team->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'ASSIGNED', 'status' => 'on-scene', 'assigned_report_id' => $report->id]);
        Event::fake([EmergencyReportUpdated::class]);
        Sanctum::actingAs($driver, ['*']);

        $this->patchJson("/api/driver-responder/assignments/{$report->id}/status", ['vehicle_status' => 'available'])
            ->assertOk()->assertJsonPath('assignment', null);

        $this->assertDatabaseHas('vehicles', ['id' => $assigned->id, 'status' => 'available', 'assigned_report_id' => null]);
        $this->assertDatabaseHas('vehicles', ['id' => $unrelated->id, 'status' => 'available', 'assigned_report_id' => null]);
        $this->assertDatabaseHas('emergency_reports', ['id' => $report->id, 'status' => 'resolved']);
        $this->assertDatabaseHas('resident_notifications', ['user_id' => $resident->getKey(), 'related_id' => $report->id, 'type' => 'report_update']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'UPDATE_REPORT', 'user_id' => $driver->getKey()]);
        Event::assertDispatched(EmergencyReportUpdated::class, fn ($event) => $event->report->id === $report->id);
    }

    public function test_report_creation_rolls_back_if_its_notification_cannot_be_saved(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $type = $this->createIncidentType();
        Sanctum::actingAs($resident, ['*']);
        ResidentNotification::creating(function (): void {
            throw new \RuntimeException('Notification write failed');
        });

        try {
            $this->mobilePostJson('/api/resident/reports', [
                'incident_type_id' => $type->id, 'reporter_type' => 'witness', 'location' => 'Town hall',
                'lat' => 14.8, 'lng' => 120.9, 'description' => 'Medical emergency',
            ])->assertInternalServerError();
        } finally {
            ResidentNotification::flushEventListeners();
        }

        $this->assertDatabaseCount('emergency_reports', 0);
        $this->assertDatabaseCount('resident_notifications', 0);
    }

    private function mobilePostJson(string $uri, array $data = [])
    {
        if ($uri === '/api/auth/mobile/register') {
            $data += ['consent' => true, 'consent_version' => config('mobile.consent_version')];
        }

        return $this->postJson($uri, $data, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function createIncidentType(): IncidentType
    {
        return IncidentType::query()->create([
            'name' => 'Medical emergency',
            'description' => 'A medical emergency requiring a response.',
            'priority' => 'high',
        ]);
    }
}
