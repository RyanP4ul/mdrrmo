<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DispatcherUser;
use App\Models\DispatchTrip;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\MaintenanceReport;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_only_can_submit_maintenance_report_and_switch_team_ambulance(): void
    {
        $team = ResponseTeam::create(['team_name' => 'Alpha Team', 'status' => 'active']);
        $driver = DriverResponderUser::factory()->create(['status' => 'active']);
        $responder = DriverResponderUser::factory()->create(['status' => 'active']);

        TeamMember::create([
            'response_team_id' => $team->id,
            'user_id' => $driver->id,
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);
        TeamMember::create([
            'response_team_id' => $team->id,
            'user_id' => $responder->id,
            'name' => $responder->full_name,
            'role' => 'Responder',
            'status' => 'active',
        ]);

        $primary = Vehicle::create([
            'name' => 'Alpha Ambulance 1',
            'plate_number' => 'AMB-101',
            'vehicle_type' => 'Ambulance',
            'response_team_id' => $team->id,
            'status' => 'available',
            'photo_path' => 'https://example.com/amb1.jpg',
        ]);
        $backup = Vehicle::create([
            'name' => 'Alpha Ambulance 2',
            'plate_number' => 'AMB-102',
            'vehicle_type' => 'Ambulance',
            'response_team_id' => $team->id,
            'status' => 'available',
        ]);
        $broken = Vehicle::create([
            'name' => 'Alpha Ambulance 3',
            'plate_number' => 'AMB-103',
            'vehicle_type' => 'Ambulance',
            'response_team_id' => $team->id,
            'status' => 'maintenance',
            'maintenance_warning' => 'Brake overhaul in progress',
        ]);
        $team->update(['active_vehicle_id' => $primary->id]);

        // Responder cannot submit maintenance report or switch vehicle
        Sanctum::actingAs($responder, ['*']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/driver-responder/maintenance-reports', [
                'vehicle_id' => $primary->id,
                'issue_type' => 'Brakes / Tires',
                'severity' => 'high',
                'description' => 'Brake pedal feels spongy during braking.',
            ])->assertForbidden();

        $this->patchJson("/api/driver-responder/teams/{$team->id}/vehicle", [
            'vehicle_id' => $backup->id,
        ])->assertForbidden();

        // Driver can view team vehicles, switch to available ambulance, and submit maintenance report
        Sanctum::actingAs($driver, ['*']);
        $bootstrap = $this->getJson('/api/driver-responder/bootstrap')->assertOk();
        $bootstrap->assertJsonPath('team_roles.0.active_vehicle.number', 'AMB-101');
        $bootstrap->assertJsonPath('team_roles.0.active_vehicle.photo_url', 'https://example.com/amb1.jpg');

        // Cannot switch to a vehicle under maintenance
        $this->patchJson("/api/driver-responder/teams/{$team->id}/vehicle", [
            'vehicle_id' => $broken->id,
        ])->assertUnprocessable();

        // Can switch to available backup ambulance
        $this->patchJson("/api/driver-responder/teams/{$team->id}/vehicle", [
            'vehicle_id' => $backup->id,
        ])->assertOk()->assertJsonPath('team_roles.0.active_vehicle_id', $backup->id);

        $this->assertDatabaseHas('response_teams', [
            'id' => $team->id,
            'active_vehicle_id' => $backup->id,
        ]);

        // Driver submits maintenance report recommending out-of-service on backup
        $reportResponse = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/driver-responder/maintenance-reports', [
                'vehicle_id' => $backup->id,
                'issue_type' => 'Engine / Mechanical',
                'severity' => 'critical',
                'mileage' => 45210,
                'description' => 'Engine overheating warning light came on.',
                'recommend_out_of_service' => true,
            ])->assertCreated();

        $reportId = $reportResponse->json('report.id');
        $this->assertNotNull($reportId);
        $this->assertDatabaseHas('maintenance_reports', [
            'id' => $reportId,
            'vehicle_id' => $backup->id,
            'reported_by' => $driver->id,
            'status' => 'pending',
            'recommend_out_of_service' => 1,
        ]);
        $this->assertNotNull($backup->fresh()->maintenance_warning);
    }

    public function test_admin_can_manage_vehicles_and_review_maintenance_reports_with_automatic_fallback(): void
    {
        $admin = AdminUser::factory()->create(['status' => 'active']);
        $team = ResponseTeam::create(['team_name' => 'Bravo Team', 'status' => 'active']);
        $driver = DriverResponderUser::factory()->create(['status' => 'active']);
        TeamMember::create([
            'response_team_id' => $team->id,
            'user_id' => $driver->id,
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);

        $v1 = Vehicle::create([
            'name' => 'Bravo Unit 1',
            'plate_number' => 'BRV-201',
            'vehicle_type' => 'Ambulance',
            'response_team_id' => $team->id,
            'status' => 'available',
        ]);
        $v2 = Vehicle::create([
            'name' => 'Bravo Unit 2',
            'plate_number' => 'BRV-202',
            'vehicle_type' => 'Ambulance',
            'response_team_id' => $team->id,
            'status' => 'available',
        ]);
        $team->update(['active_vehicle_id' => $v1->id]);

        $report = MaintenanceReport::create([
            'reference' => 'MNT-20261110-TEST01',
            'vehicle_id' => $v1->id,
            'response_team_id' => $team->id,
            'reported_by' => $driver->id,
            'issue_type' => 'Transmission',
            'severity' => 'high',
            'description' => 'Gear slipping between 2nd and 3rd gear.',
            'recommend_out_of_service' => true,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin, ['*']);

        // Admin reviews and puts vehicle in maintenance -> team active vehicle switches to v2
        $this->patchJson("/api/operations/maintenance-reports/{$report->id}/review", [
            'status' => 'in_maintenance',
            'admin_notes' => 'Scheduled with municipal motorpool.',
        ])->assertOk()->assertJsonPath('status', 'in_maintenance');

        $this->assertSame('maintenance', $v1->fresh()->status);
        $this->assertSame($v2->id, $team->fresh()->active_vehicle_id);
        $this->assertDatabaseHas('driver_notifications', [
            'driver_id' => $driver->id,
            'title' => 'Maintenance report in maintenance',
        ]);

        // Admin resolves the maintenance report -> vehicle returns to available
        $this->patchJson("/api/operations/maintenance-reports/{$report->id}/review", [
            'status' => 'resolved',
            'admin_notes' => 'Transmission serviced and road-tested.',
        ])->assertOk()->assertJsonPath('status', 'resolved');

        $this->assertSame('available', $v1->fresh()->status);
        $this->assertNull($v1->fresh()->maintenance_warning);
    }

    public function test_driver_and_responder_receive_role_specific_record_permissions(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $team = ResponseTeam::create(['team_name' => 'Charlie Team', 'status' => 'active']);
        $driver = DriverResponderUser::factory()->create(['status' => 'active']);
        $responder = DriverResponderUser::factory()->create(['status' => 'active']);

        TeamMember::create([
            'response_team_id' => $team->id,
            'user_id' => $driver->id,
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);
        TeamMember::create([
            'response_team_id' => $team->id,
            'user_id' => $responder->id,
            'name' => $responder->full_name,
            'role' => 'Responder',
            'status' => 'active',
        ]);

        $type = IncidentType::firstOrCreate(
            ['name' => 'Medical'],
            ['description' => 'Medical emergency', 'priority' => 'high', 'color' => '#123456']
        );

        $report = EmergencyReport::create([
            'reference' => 'RPT-'.Str::random(8),
            'incident_type_id' => $type->id,
            'reported_by' => $resident->id,
            'reported_by_type' => $resident->getMorphClass(),
            'reporter_type' => 'witness',
            'reporter_name' => $resident->full_name,
            'location' => 'Poblacion',
            'description' => 'Responder role test',
            'lat' => 14.8,
            'lng' => 120.9,
            'status' => 'dispatched',
            'priority' => 'high',
            'response_team_id' => $team->id,
            'assigned_driver_id' => $driver->id,
            'reported_at' => now()->subMinutes(15),
        ]);

        // Driver can both edit trip and fill patient form
        Sanctum::actingAs($driver, ['*']);
        $this->getJson("/api/driver-responder/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('can_edit_trip', true)
            ->assertJsonPath('can_fill_patient_form', true);

        // Responder can fill patient form but cannot edit trip record
        Sanctum::actingAs($responder, ['*']);
        $this->getJson("/api/driver-responder/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('can_edit_trip', false)
            ->assertJsonPath('can_fill_patient_form', true);

        $this->putJson("/api/driver-responder/reports/{$report->id}/trip", [
            'destination' => 'Provincial Hospital',
        ])->assertForbidden();
    }

    public function test_dispatcher_can_manage_vehicles_assign_team_vehicle_and_manual_dispatch_team(): void
    {
        $dispatcher = DispatcherUser::factory()->create(['status' => 'active', 'shift_team' => 'day']);
        $driver1 = DriverResponderUser::factory()->create(['status' => 'active', 'shift_team' => 'day']);
        $responder1 = DriverResponderUser::factory()->create(['status' => 'active', 'shift_team' => 'day']);
        $driver2 = DriverResponderUser::factory()->create(['status' => 'active', 'shift_team' => 'day']);

        // Dispatcher creates vehicles using the 3 allowed types: rescue-truck, ambulance, tow-truck
        $this->actingAs($dispatcher)
            ->post(route('dispatcher.vehicles.store'), [
                'name' => 'Balagtas Rescue 1',
                'plate_number' => 'RSC-101',
                'vehicle_type' => 'rescue-truck',
                'status' => 'available',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $this->actingAs($dispatcher)
            ->post(route('dispatcher.vehicles.store'), [
                'name' => 'Balagtas Tow 1',
                'plate_number' => 'TOW-301',
                'vehicle_type' => 'tow-truck',
                'status' => 'available',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $rescueVehicle = Vehicle::where('plate_number', 'RSC-101')->firstOrFail();
        $towVehicle = Vehicle::where('plate_number', 'TOW-301')->firstOrFail();

        // Rejects creating a response team with more than one Driver
        $this->actingAs($dispatcher)
            ->post(route('dispatcher.responders.store'), [
                'team_name' => 'Invalid Two Drivers',
                'status' => 'active',
                'members' => [
                    ['user_id' => $driver1->id, 'role' => 'Driver'],
                    ['user_id' => $responder1->id, 'role' => 'Driver'],
                ],
            ])->assertSessionHasErrors('members');

        // Dispatcher creates Team Alpha with 1 Driver, 1 Responder, and assigns rescue vehicle
        $this->actingAs($dispatcher)
            ->post(route('dispatcher.responders.store'), [
                'team_name' => 'Team Alpha',
                'status' => 'active',
                'vehicle_id' => $rescueVehicle->id,
                'driver_user_id' => $driver1->id,
                'members' => [
                    ['user_id' => $driver1->id, 'role' => 'Driver'],
                    ['user_id' => $responder1->id, 'role' => 'Responder'],
                ],
            ])->assertSessionHasNoErrors()->assertRedirect();

        $teamAlpha = ResponseTeam::where('team_name', 'Team Alpha')->firstOrFail();
        $this->assertSame($rescueVehicle->id, $teamAlpha->active_vehicle_id);
        $this->assertSame($teamAlpha->id, $rescueVehicle->fresh()->response_team_id);
        $this->assertSame(1, $teamAlpha->members()->where('role', 'Driver')->count());

        // Dispatcher creates Team Bravo with 1 Driver and assigns tow vehicle
        $this->actingAs($dispatcher)
            ->post(route('dispatcher.responders.store'), [
                'team_name' => 'Team Bravo',
                'status' => 'active',
                'vehicle_id' => $towVehicle->id,
                'driver_user_id' => $driver2->id,
                'members' => [
                    ['user_id' => $driver2->id, 'role' => 'Driver'],
                ],
            ])->assertSessionHasNoErrors()->assertRedirect();

        $teamBravo = ResponseTeam::where('team_name', 'Team Bravo')->firstOrFail();

        // Dispatcher manually selects Team Bravo (instead of auto-assigned Team Alpha) in Dispatch Operations
        $type = IncidentType::firstOrCreate(
            ['name' => 'Vehicular Accident'],
            ['description' => 'Road collision', 'priority' => 'high', 'color' => '#2563eb']
        );

        $this->actingAs($dispatcher)
            ->post(route('dispatcher.dispatch.store'), [
                'incident_type_id' => $type->id,
                'team_id' => $teamBravo->id,
                'reporter_name' => 'Pedro Santos',
                'reporter_contact' => '09171234567',
                'location' => 'MacArthur Highway, Balagtas',
                'lat' => 14.820056,
                'lng' => 120.904576,
                'description' => 'Two-vehicle collision needing tow truck.',
                'reporter_type' => 'witness',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $dispatchedReport = EmergencyReport::latest('id')->firstOrFail();
        $this->assertSame($teamBravo->id, $dispatchedReport->response_team_id);
        $this->assertSame($driver2->id, $dispatchedReport->assigned_driver_id);
    }

    public function test_custom_others_incident_type_and_on_scene_driver_only_backup_request(): void
    {
        $resident = ResidentUser::factory()->accepted()->create(['status' => 'active']);
        $team = ResponseTeam::create(['team_name' => 'Delta Team', 'status' => 'active', 'member_roles_enabled' => true]);
        $driver = DriverResponderUser::factory()->create(['status' => 'active']);
        $responder = DriverResponderUser::factory()->create(['status' => 'active']);

        TeamMember::create([
            'response_team_id' => $team->id,
            'user_id' => $driver->id,
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);
        TeamMember::create([
            'response_team_id' => $team->id,
            'user_id' => $responder->id,
            'name' => $responder->full_name,
            'role' => 'Responder',
            'status' => 'active',
        ]);

        // 1. Resident submits report with custom "Others" incident_type_name
        Sanctum::actingAs($resident, ['*']);
        $resReport = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/resident/reports', [
                'incident_type_name' => 'Chemical Spill',
                'reporter_type' => 'witness',
                'location' => 'Industrial Zone, Balagtas',
                'lat' => 14.820056,
                'lng' => 120.904576,
                'description' => 'Hazardous material leak from container.',
            ])->assertCreated();

        $this->assertSame('Chemical Spill', $resReport->json('report.type'));

        // 2. Responder submits Emergency Report with custom "Others" incident_type_name
        Sanctum::actingAs($responder, ['*']);
        $empReport = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/driver-responder/reports', [
                'incident_type_name' => 'Collapsed Structure',
                'location' => 'Barangay Wawa, Balagtas',
                'lat' => 14.820056,
                'lng' => 120.904576,
                'priority' => 'critical',
                'patient_name' => 'Mario Reyes',
                'patient_age' => 38,
                'symptoms' => 'Trapped under debris',
                'contact_person' => 'Elena Reyes',
                'contact_number' => '09171234567',
            ])->assertCreated();

        $this->assertSame('Collapsed Structure', $empReport->json('report.type'));

        // 3. Dispatch Delta Team to report
        $activeReport = EmergencyReport::findOrFail($empReport->json('report.id'));
        $activeReport->update([
            'status' => 'dispatched',
            'response_team_id' => $team->id,
            'assigned_driver_id' => $driver->id,
        ]);

        // Driver cannot call backup before arriving on scene (e.g. while en-route)
        DispatchTrip::create([
            'emergency_report_id' => $activeReport->id,
            'driver_name' => $driver->full_name,
            'started_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($driver, ['*']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/driver-responder/assignments/{$activeReport->id}/backup")
            ->assertUnprocessable();

        // Advance to On Scene
        DispatchTrip::where('emergency_report_id', $activeReport->id)->update([
            'on_scene_at' => now(),
        ]);

        // Non-driver Responder cannot call backup even when on scene
        Sanctum::actingAs($responder, ['*']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/driver-responder/assignments/{$activeReport->id}/backup")
            ->assertNotFound();

        // Driver on scene can call a backup -> creates pending backup report for Dispatcher
        Sanctum::actingAs($driver, ['*']);
        $backupResp = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/driver-responder/assignments/{$activeReport->id}/backup", [
                'notes' => 'Need heavy rescue truck on scene.',
            ])->assertCreated();

        $backupRef = $backupResp->json('reference');
        $this->assertNotEmpty($backupRef);
        $this->assertDatabaseHas('emergency_reports', [
            'reference' => $backupRef,
            'status' => 'pending',
            'priority' => 'critical',
            'location' => 'Barangay Wawa, Balagtas',
        ]);
    }
}
