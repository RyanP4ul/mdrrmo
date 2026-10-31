<?php

namespace Tests\Feature;

use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        Sanctum::actingAs($resident);
        $response = $this->postJson('/api/resident/reports', [
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

        Sanctum::actingAs($otherResident);
        $this->getJson('/api/resident/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'reports')
            ->assertJsonCount(0, 'notifications');
    }

    public function test_resident_schedule_requests_are_persisted_with_a_notification(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        Sanctum::actingAs($resident);

        $this->postJson('/api/resident/schedules', [
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

        Sanctum::actingAs($driver);
        $this->patchJson("/api/driver-responder/assignments/{$report->getKey()}/status", [
            'vehicle_status' => 'en-route',
        ])->assertOk()->assertJsonPath('assignment.vehicle_status', 'en-route');

        $this->patchJson("/api/driver-responder/assignments/{$report->getKey()}/status", [
            'vehicle_status' => 'on-scene',
        ])->assertOk()->assertJsonPath('assignment.vehicle_status', 'on-scene');

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

        Sanctum::actingAs($driver);
        $this->putJson('/api/driver-responder/profile', [
            'name' => 'Taylor Driver',
            'contact' => '09123456789',
            'vehicle_type' => 'rescue-van',
            'vehicle_number' => 'TEST-003',
        ])->assertOk()
            ->assertJsonPath('profile.name', 'Taylor Driver')
            ->assertJsonPath('profile.vehicle_type', 'rescue-van');

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->getKey(),
            'first_name' => 'Taylor',
            'last_name' => 'Driver',
            'contact_number' => '09123456789',
        ]);
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->getKey(),
            'vehicle_type' => 'rescue-van',
            'plate_number' => 'TEST-003',
        ]);

        $this->postJson('/api/driver-responder/reports', [
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
        $this->postJson('/api/auth/mobile/register', [
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

    private function createIncidentType(): IncidentType
    {
        return IncidentType::query()->create([
            'name' => 'Medical emergency',
            'description' => 'A medical emergency requiring a response.',
            'priority' => 'high',
        ]);
    }
}
