<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminArchivesTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_roles_cannot_access_archives(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $driver = DriverResponderUser::factory()->create();
        $resident = ResidentUser::factory()->create();

        foreach ([$dispatcher, $driver, $resident] as $actor) {
            $this->actingAs($actor)->get(route('admin.archives.index'))->assertForbidden();
        }
    }

    public function test_deleting_user_vehicle_incident_type_and_team_archives_instead_of_hard_deleting(): void
    {
        $admin = AdminUser::factory()->create();
        $dispatcher = DispatcherUser::factory()->create();
        $resident = ResidentUser::factory()->create(['status' => 'inactive']);
        $vehicle = Vehicle::create([
            'name' => 'Rescue One',
            'plate_number' => 'ABC-1234',
            'vehicle_type' => 'ambulance',
            'status' => 'available',
        ]);
        $incidentType = IncidentType::create([
            'name' => 'Flooding',
            'description' => 'Flood response',
            'priority' => 'high',
            'color' => '#ea580c',
        ]);
        $team = ResponseTeam::create([
            'team_name' => 'Alpha Team',
            'status' => 'active',
            'shift_team' => 'day',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', Account::keyFor($resident)))
            ->assertRedirect();

        $this->actingAs($admin)
            ->delete(route('admin.vehicles.destroy', $vehicle->id))
            ->assertRedirect();

        $this->actingAs($admin)
            ->delete(route('admin.incident-types.destroy', $incidentType->id))
            ->assertRedirect();

        $this->actingAs($dispatcher)
            ->delete(route('dispatcher.responders.destroy', $team->id))
            ->assertRedirect();

        $this->assertSoftDeleted('residents', ['id' => $resident->id]);
        $this->assertSoftDeleted('vehicles', ['id' => $vehicle->id]);
        $this->assertSoftDeleted('incident_types', ['id' => $incidentType->id]);
        $this->assertSoftDeleted('response_teams', ['id' => $team->id]);

        $this->actingAs($admin)
            ->get(route('admin.archives.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Archives/Index')
                ->where('counts.all', 4)
                ->where('counts.users', 1)
                ->where('counts.vehicles', 1)
                ->where('counts.incident-types', 1)
                ->where('counts.teams', 1)
            );
    }

    public function test_only_resolved_or_invalid_reports_can_be_archived_and_historical_relations_stay_readable(): void
    {
        $admin = AdminUser::factory()->create();
        $incidentType = IncidentType::create([
            'name' => 'Vehicular Accident',
            'description' => 'Road incident',
            'priority' => 'high',
            'color' => '#ea580c',
        ]);

        $pendingReport = EmergencyReport::create([
            'reference' => 'RPT-2026-PEND01',
            'incident_type_id' => $incidentType->id,
            'location' => 'Barangay Saluysoy',
            'lat' => 14.736,
            'lng' => 120.96,
            'description' => 'Active report',
            'status' => 'pending',
            'priority' => 'high',
            'reporter_name' => 'Juan Dela Cruz',
        ]);

        $resolvedReport = EmergencyReport::create([
            'reference' => 'RPT-2026-RES001',
            'incident_type_id' => $incidentType->id,
            'location' => 'Barangay Malhacan',
            'lat' => 14.74,
            'lng' => 120.97,
            'description' => 'Closed report',
            'status' => 'resolved',
            'priority' => 'medium',
            'reporter_name' => 'Maria Santos',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.reports.archive', $pendingReport))
            ->assertSessionHasErrors('report');

        $this->assertDatabaseHas('emergency_reports', ['id' => $pendingReport->id, 'deleted_at' => null]);

        $this->actingAs($admin)
            ->patch(route('admin.reports.archive', $resolvedReport))
            ->assertRedirect(route('admin.reports.index'));

        $this->assertSoftDeleted('emergency_reports', ['id' => $resolvedReport->id]);

        // Archiving the incident type must not break viewing the still-active pending report
        $this->actingAs($admin)
            ->delete(route('admin.incident-types.destroy', $incidentType))
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.reports.show', $pendingReport))
            ->assertOk();
    }

    public function test_deleting_inside_archives_enforces_30_day_wait_before_permanent_deletion(): void
    {
        Storage::fake('public');
        $admin = AdminUser::factory()->create();
        $photoPath = Storage::disk('public')->put('vehicles', UploadedFile::fake()->image('truck.jpg'));

        $vehicle = Vehicle::create([
            'name' => 'Unit 9',
            'plate_number' => 'XYZ-999',
            'vehicle_type' => 'fire-truck',
            'status' => 'available',
            'photo_path' => $photoPath,
        ]);

        // Step 1: Move vehicle to Archives
        $this->actingAs($admin)
            ->delete(route('admin.vehicles.destroy', $vehicle))
            ->assertRedirect();

        // Step 2: Click Delete inside Archives -> starts 30-day countdown
        $this->actingAs($admin)
            ->patch(route('admin.archives.schedule-delete', ['type' => 'vehicles', 'key' => (string) $vehicle->id]))
            ->assertRedirect();

        $vehicle->refresh();
        $this->assertNotNull($vehicle->deleted_at);
        $this->assertNotNull($vehicle->purge_scheduled_at);
        $this->assertSame(30, $vehicle->daysUntilPurge());

        // Step 3: At day 0 and day 29, running archives:purge must NOT delete the record
        $this->artisan('archives:purge')->assertSuccessful();
        $this->assertSoftDeleted('vehicles', ['id' => $vehicle->id]);
        Storage::disk('public')->assertExists($photoPath);

        $this->travel(29)->days();
        $this->artisan('archives:purge')->assertSuccessful();
        $this->assertSoftDeleted('vehicles', ['id' => $vehicle->id]);
        Storage::disk('public')->assertExists($photoPath);

        // Step 4: After 30 days have elapsed, archives:purge permanently deletes record and files
        $this->travel(1)->days();
        $this->artisan('archives:purge')->assertSuccessful();

        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
        Storage::disk('public')->assertMissing($photoPath);
        $this->assertTrue(
            AuditLog::where('action', 'PERMANENT_DELETE_VEHICLE')->exists()
        );
    }

    public function test_admin_can_cancel_scheduled_deletion_or_restore_during_30_day_wait(): void
    {
        $admin = AdminUser::factory()->create();
        $residentA = ResidentUser::factory()->create(['status' => 'inactive']);
        $residentB = ResidentUser::factory()->create(['status' => 'inactive']);

        // Archive both
        $this->actingAs($admin)->delete(route('admin.users.destroy', Account::keyFor($residentA)))->assertRedirect();
        $this->actingAs($admin)->delete(route('admin.users.destroy', Account::keyFor($residentB)))->assertRedirect();

        // Schedule 30-day deletion for both
        $this->actingAs($admin)->patch(route('admin.archives.schedule-delete', ['type' => 'users', 'key' => Account::keyFor($residentA)]))->assertRedirect();
        $this->actingAs($admin)->patch(route('admin.archives.schedule-delete', ['type' => 'users', 'key' => Account::keyFor($residentB)]))->assertRedirect();

        // Cancel deletion on A (stays in Archives), Restore B (becomes Active)
        $this->travel(15)->days();
        $this->actingAs($admin)->patch(route('admin.archives.cancel-delete', ['type' => 'users', 'key' => Account::keyFor($residentA)]))->assertRedirect();
        $this->actingAs($admin)->patch(route('admin.archives.restore', ['type' => 'users', 'key' => Account::keyFor($residentB)]))->assertRedirect();

        // Travel past 30 days and run purge -> neither A nor B should be deleted
        $this->travel(20)->days();
        $this->artisan('archives:purge')->assertSuccessful();

        $this->assertSoftDeleted('residents', ['id' => $residentA->id]);
        $this->assertNull(ResidentUser::withTrashed()->find($residentA->id)?->purge_scheduled_at);

        $this->assertDatabaseHas('residents', ['id' => $residentB->id, 'deleted_at' => null, 'purge_scheduled_at' => null]);
    }

    public function test_active_accounts_cannot_be_archived_and_an_emergency_protects_both_team_members(): void
    {
        $admin = AdminUser::factory()->create();
        $driver = DriverResponderUser::factory()->create(['contact_number' => '09171234567']);
        $responder = DriverResponderUser::factory()->create(['contact_number' => '09171234568']);
        $team = ResponseTeam::create(['team_name' => 'Emergency pair', 'status' => 'active']);
        foreach ([$driver, $responder] as $member) {
            $team->members()->create(['user_id' => $member->id, 'name' => $member->full_name, 'role' => $member->is($driver) ? 'Driver' : 'Responder', 'status' => 'active']);
        }
        $type = IncidentType::create(['name' => 'Medical', 'description' => 'Medical', 'priority' => 'high', 'color' => '#223344']);
        $report = EmergencyReport::create([
            'reference' => EmergencyReport::generateReference(), 'incident_type_id' => $type->id,
            'reporter_type' => 'witness', 'reporter_name' => 'Town Hall Staff', 'location' => 'Town hall', 'description' => '',
            'lat' => 14.8, 'lng' => 120.9, 'priority' => 'high', 'status' => 'dispatched',
            'response_team_id' => $team->id, 'assigned_driver_id' => $driver->id,
        ]);

        $this->actingAs($admin);
        $this->delete(route('admin.users.destroy', Account::keyFor($responder)))->assertSessionHasErrors('user');
        foreach ([$driver, $responder] as $member) {
            $key = Account::keyFor($member);
            $this->patch(route('admin.users.status', $key), ['status' => 'inactive'])->assertSessionHasErrors('status');
            $this->putJson(route('admin.users.update', $key), $member->only(['first_name', 'last_name', 'email', 'contact_number']) + [
                'role' => 'driver/responder', 'status' => 'inactive',
            ])->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->assertSame('active', $member->fresh()->status);
        }
        $report->update(['status' => 'resolved']);
        $key = Account::keyFor($responder);
        $this->patch(route('admin.users.status', $key), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->delete(route('admin.users.destroy', $key))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($responder);
    }
}
