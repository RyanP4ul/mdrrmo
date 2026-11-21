<?php

namespace Tests\Feature;

use App\Models\DispatchAssignment;
use App\Models\DispatchTrip;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResponseTeam;
use App\Models\Shift;
use App\Models\TeamMember;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupVehicleDispatchTest extends TestCase
{
    use DatabaseMigrations;

    private function createTeamWithDriverAndVehicle(
        string $teamName,
        string $driverFirst,
        string $vehicleType,
        string $vehicleName,
        string $plate,
    ): array {
        $driver = DriverResponderUser::factory()->create([
            'first_name' => $driverFirst,
            'last_name' => 'Driver',
            'status' => 'active',
        ]);
        $team = ResponseTeam::create([
            'team_name' => $teamName,
            'status' => 'active',
            'specializations' => ['*'],
            'member_roles_enabled' => true,
        ]);
        $member = TeamMember::create([
            'response_team_id' => $team->id,
            'user_id' => $driver->id,
            'name' => $driver->full_name,
            'role' => 'Driver',
            'status' => 'active',
        ]);
        $shift = Shift::create([
            'response_team_id' => $team->id,
            'start_time' => now()->subHour(),
            'end_time' => now()->addHour(),
            'status' => 'on-shift',
        ]);
        $shift->members()->attach($member);
        $vehicle = Vehicle::create([
            'response_team_id' => $team->id,
            'name' => $vehicleName,
            'vehicle_type' => $vehicleType,
            'plate_number' => $plate,
            'status' => 'available',
        ]);
        $team->update(['active_vehicle_id' => $vehicle->id]);

        return [$team->fresh(), $driver, $vehicle->fresh()];
    }

    public function test_full_backup_request_temporary_vehicle_dispatch_and_return_lifecycle(): void
    {
        $dispatcher = DispatcherUser::factory()->create(['status' => 'active']);
        $incidentType = IncidentType::create([
            'name' => 'Vehicular Accident',
            'description' => 'Road collision',
            'priority' => 'high',
            'color' => '#dc2626',
        ]);

        [$teamA, $driverA, $ambA] = $this->createTeamWithDriverAndVehicle(
            'Team A', 'Alpha', 'ambulance', 'Ambulance 01', 'AMB-01',
        );
        [$teamB, $driverB, $ambB] = $this->createTeamWithDriverAndVehicle(
            'Team B', 'Bravo', 'ambulance', 'Ambulance 02', 'AMB-02',
        );
        $towTruck = Vehicle::create([
            'response_team_id' => null,
            'name' => 'Tow Truck 01',
            'vehicle_type' => 'tow-truck',
            'plate_number' => 'TOW-01',
            'status' => 'available',
        ]);

        // Enable auto-dispatch to confirm backup requests stay pending for dispatcher review.
        DB::table('automatic_dispatch_settings')->update(['enabled' => true]);

        // Team A is on scene at a Vehicular Accident.
        $incident = EmergencyReport::create([
            'reference' => 'RPT-2026-ORIG0001',
            'incident_type_id' => $incidentType->id,
            'response_team_id' => $teamA->id,
            'assigned_driver_id' => $driverA->id,
            'location' => 'MacArthur Highway, Balagtas',
            'lat' => 14.815,
            'lng' => 120.909,
            'description' => 'Two-car collision blocking southbound lane',
            'status' => 'dispatched',
            'priority' => 'high',
            'source' => 'emergency-call',
            'reporter_type' => 'witness',
            'reporter_name' => 'Caller One',
            'reported_at' => now()->subMinutes(15),
        ]);
        DispatchTrip::create([
            'emergency_report_id' => $incident->id,
            'driver_name' => $driverA->full_name,
            'started_at' => now()->subMinutes(10),
            'on_scene_at' => now()->subMinutes(2),
        ]);

        // 1. Driver A requests a Tow Truck for backup while on scene.
        Sanctum::actingAs($driverA, ['*']);
        $backupResponse = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/driver-responder/assignments/{$incident->id}/backup", [
                'requested_vehicle_type' => 'tow-truck',
                'notes' => 'Vehicle stuck across road',
            ])->assertCreated();

        $backupRef = $backupResponse->json('reference');
        $backupReport = EmergencyReport::where('reference', $backupRef)->firstOrFail();

        $this->assertSame('pending', $backupReport->status);
        $this->assertSame($incident->id, $backupReport->parent_report_id);
        $this->assertSame($teamA->id, $backupReport->requested_by_team_id);
        $this->assertSame('tow-truck', $backupReport->requested_vehicle_type);
        $this->assertTrue($backupReport->isBackupRequest());

        // 2. Dispatcher opens dashboard and sees backup metadata + available tow truck + Team B default vehicle.
        $this->actingAs($dispatcher, 'web')
            ->get(route('dispatcher.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dispatcher/Dashboard')
                ->has('incoming', fn (Assert $incoming) => $incoming
                    ->where('0.reference', $backupRef)
                    ->where('0.is_backup', true)
                    ->where('0.requested_vehicle_type', 'tow-truck')
                    ->where('0.requested_vehicle_label', 'Tow Truck')
                    ->where('0.requested_by_team.team_name', 'Team A')
                    ->etc())
                ->has('vehicles', 3));

        // 3. Dispatcher dispatches Team B with Tow Truck 01 for this backup.
        $this->actingAs($dispatcher, 'web')
            ->post(route('dispatcher.dispatch.store'), [
                'report_id' => $backupReport->id,
                'team_id' => $teamB->id,
                'vehicle_id' => $towTruck->id,
            ])
            ->assertRedirect(route('dispatcher.reports.show', $backupReport));

        $backupReport->refresh();
        $towTruck->refresh();
        $ambB->refresh();
        $teamB->refresh();

        $this->assertSame('dispatched', $backupReport->status);
        $this->assertSame($teamB->id, $backupReport->response_team_id);
        $this->assertSame($driverB->id, $backupReport->assigned_driver_id);

        // Tow Truck 01 is assigned to the backup operation; permanent ownership is untouched.
        $this->assertSame($backupReport->id, $towTruck->assigned_report_id);
        $this->assertNull($towTruck->response_team_id);

        // Team B's default vehicle (Ambulance 02) remains at the station and unchanged.
        $this->assertSame($ambB->id, $teamB->active_vehicle_id);
        $this->assertSame($teamB->id, $ambB->response_team_id);
        $this->assertNull($ambB->assigned_report_id);
        $this->assertSame('available', $ambB->status);

        // DispatchAssignment table records the backup operation cleanly.
        $this->assertDatabaseHas('dispatch_assignments', [
            'emergency_report_id' => $backupReport->id,
            'response_team_id' => $teamB->id,
            'vehicle_id' => $towTruck->id,
            'assignment_type' => 'backup',
            'status' => 'dispatched',
        ]);

        // Tow Truck 01 cannot be double-booked onto another backup while dispatched.
        $secondBackup = EmergencyReport::create([
            'reference' => 'RPT-2026-BKUP0002',
            'parent_report_id' => $incident->id,
            'requested_by_team_id' => $teamA->id,
            'requested_vehicle_type' => 'tow-truck',
            'incident_type_id' => $incidentType->id,
            'location' => 'Elsewhere',
            'lat' => 14.82,
            'lng' => 120.91,
            'description' => 'Second backup test',
            'status' => 'pending',
            'priority' => 'critical',
            'source' => 'mobile-app',
            'reporter_type' => 'witness',
            'reporter_name' => 'Alpha Driver',
            'reported_at' => now(),
        ]);

        $this->actingAs($dispatcher, 'web')
            ->post(route('dispatcher.dispatch.store'), [
                'report_id' => $secondBackup->id,
                'team_id' => $teamB->id,
                'vehicle_id' => $towTruck->id,
            ])
            ->assertSessionHasErrors('vehicle_id');

        // 4. Driver B1 sees Tow Truck 01 on bootstrap and is authorized to operate/track it.
        $this->assertTrue(Gate::forUser($driverB)->allows('update', $towTruck));
        Sanctum::actingAs($driverB, ['*']);
        $bootstrap = $this->getJson('/api/driver-responder/bootstrap')->assertOk();
        $bootstrap->assertJsonPath('assignment.id', $backupReport->id);
        $bootstrap->assertJsonPath('assignment.vehicle_id', $towTruck->id);
        $bootstrap->assertJsonPath('assignment.vehicle', 'TOW-01');
        $this->assertTrue(
            collect($bootstrap->json('vehicles'))->contains('id', $towTruck->id),
            'Temporary backup vehicle should be included in driver bootstrap vehicles list.',
        );

        // 5. Driver B1 completes the backup operation: en-route -> on-scene -> returning -> arrived.
        $this->patchJson("/api/driver-responder/assignments/{$backupReport->id}/status", ['response_status' => 'en-route'])->assertOk();
        $this->assertSame('en-route', $towTruck->fresh()->status);

        $this->patchJson("/api/driver-responder/assignments/{$backupReport->id}/status", ['response_status' => 'on-scene'])->assertOk();
        $this->assertSame('on-scene', $towTruck->fresh()->status);

        $this->patchJson("/api/driver-responder/assignments/{$backupReport->id}/status", ['response_status' => 'returning'])->assertOk();
        $this->assertSame('returning', $towTruck->fresh()->status);

        $this->patchJson("/api/driver-responder/assignments/{$backupReport->id}/status", ['response_status' => 'arrived'])->assertOk();

        // After completion: Tow Truck 01 is Available again, DispatchAssignment is completed, Team B default is still Ambulance 02.
        $towTruck->refresh();
        $ambB->refresh();
        $teamB->refresh();

        $this->assertSame('resolved', $backupReport->fresh()->status);
        $this->assertSame('available', $towTruck->status);
        $this->assertNull($towTruck->assigned_report_id);
        $this->assertNull($towTruck->response_team_id);

        $this->assertSame($ambB->id, $teamB->active_vehicle_id);
        $this->assertSame('available', $ambB->status);
        $this->assertNull($ambB->assigned_report_id);

        $this->assertDatabaseHas('dispatch_assignments', [
            'emergency_report_id' => $backupReport->id,
            'vehicle_id' => $towTruck->id,
            'assignment_type' => 'backup',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('dispatch_trips', [
            'emergency_report_id' => $backupReport->id,
            'vehicle_number' => 'TOW-01',
        ]);
        $this->assertNotNull(
            DispatchAssignment::where('emergency_report_id', $backupReport->id)->value('completed_at'),
        );
    }
}
