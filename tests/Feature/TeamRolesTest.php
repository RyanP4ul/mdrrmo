<?php

namespace Tests\Feature;

use App\Events\TeamRoleUpdated;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Services\DispatchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamRolesTest extends TestCase
{
    use RefreshDatabase;

    private function team(): array
    {
        $team = ResponseTeam::create(['team_name' => 'Team '.ResponseTeam::count(), 'status' => 'active', 'member_roles_enabled' => true]);
        $members = [];
        foreach (range(1, 2) as $unused) {
            $user = DriverResponderUser::factory()->create();
            $members[] = $team->members()->create(['user_id' => $user->id, 'name' => $user->full_name, 'role' => 'Responder', 'status' => 'active']);
        }

        return [$team, ...$members];
    }

    private function report(ResponseTeam $team, ?int $assigned = null): EmergencyReport
    {
        $type = IncidentType::firstOrCreate(['name' => 'Medical Emergency'], ['description' => 'Medical incident', 'priority' => 'high']);

        return EmergencyReport::create([
            'reference' => EmergencyReport::generateReference(), 'incident_type_id' => $type->id,
            'reporter_name' => 'Resident', 'location' => 'Town hall', 'description' => 'Needs help',
            'lat' => 14.82, 'lng' => 120.90, 'status' => $assigned ? 'dispatched' : 'pending',
            'response_team_id' => $team->id, 'assigned_driver_id' => $assigned, 'reported_at' => now(),
        ]);
    }

    private function role(ResponseTeam $team): string
    {
        return '/api/driver-responder/teams/'.$team->id.'/role';
    }

    public function test_only_one_member_can_claim_driver_and_releasing_it_allows_the_next_member(): void
    {
        Event::fake([TeamRoleUpdated::class]);
        [$team, $first, $second] = $this->team();
        Sanctum::actingAs($first->user, ['driver-read', 'driver-write']);
        $this->patchJson($this->role($team), ['role' => 'Driver'])->assertOk()
            ->assertJsonPath('team_roles.0.role', 'Driver');
        Sanctum::actingAs($second->user, ['driver-read', 'driver-write']);
        $this->patchJson($this->role($team), ['role' => 'Driver'])->assertConflict();
        $this->getJson('/api/driver-responder/bootstrap')->assertOk()
            ->assertJsonPath('team_roles.0.can_be_driver', false)->assertJsonPath('team_roles.0.driver_name', $first->name)
            ->assertJsonPath('can_dispatch', false);
        $this->assertSame('Responder', $second->fresh()->role);
        Sanctum::actingAs($first->user, ['driver-write']);
        $this->patchJson($this->role($team), ['role' => 'Responder'])->assertOk();
        Sanctum::actingAs($second->user, ['driver-write']);
        $this->patchJson($this->role($team), ['role' => 'Driver'])->assertOk();
        $this->assertSame(1, $team->members()->where('role', 'Driver')->count());
        $this->assertSame('Driver', $second->fresh()->role);
        Event::assertDispatchedTimes(TeamRoleUpdated::class, 3);
    }

    public function test_role_changes_are_scoped_to_the_callers_active_membership(): void
    {
        [$team, $member] = $this->team();
        $this->patchJson($this->role($team), ['role' => 'Driver'])->assertUnauthorized();
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['*']);
        $this->patchJson($this->role($team), ['role' => 'Driver'])->assertForbidden();
        Sanctum::actingAs(DriverResponderUser::factory()->create(), ['driver-write']);
        $this->patchJson($this->role($team), ['role' => 'Driver'])->assertNotFound();
        Sanctum::actingAs($member->user, ['driver-read']);
        $this->patchJson($this->role($team), ['role' => 'Driver'])->assertForbidden();
        Sanctum::actingAs($member->user, ['driver-write']);
        $this->patchJson($this->role($team), ['role' => 'admin'])->assertUnprocessable();
        $member->update(['status' => 'inactive']);
        $this->patchJson($this->role($team), ['role' => 'Driver'])->assertNotFound();
    }

    public function test_each_team_has_its_own_driver_slot(): void
    {
        [$one, $first] = $this->team();
        [$two, $second] = $this->team();
        foreach ([[$one, $first], [$two, $second]] as [$team, $member]) {
            Sanctum::actingAs($member->user, ['driver-write']);
            $this->patchJson($this->role($team), ['role' => 'Driver'])->assertOk();
        }
        $this->assertSame(2, TeamMember::where('role', 'Driver')->count());
    }

    public function test_database_constraint_blocks_a_second_driver_even_if_a_writer_bypasses_the_api(): void
    {
        [$team, $first, $second] = $this->team();
        $first->update(['role' => 'Driver']);
        $this->expectException(QueryException::class);
        DB::table('team_members')->where('id', $second->id)->update(['role' => 'Driver']);
    }

    public function test_active_dispatch_locks_role_changes_and_dispatcher_reassignment(): void
    {
        [$team, $first, $second] = $this->team();
        $first->update(['role' => 'Driver']);
        $this->report($team, $first->user_id);
        Sanctum::actingAs($first->user, ['driver-write', 'driver-read']);
        $this->patchJson($this->role($team), ['role' => 'Responder'])->assertConflict();
        $this->getJson('/api/driver-responder/bootstrap')->assertOk()->assertJsonPath('team_roles.0.locked', true);
        $this->actingAs(DispatcherUser::factory()->create(), 'web')->putJson('/dispatcher/responders/'.$team->id, [
            'team_name' => $team->team_name, 'status' => 'active', 'driver_user_id' => $second->user_id,
            'members' => [['id' => $first->id, 'user_id' => $first->user_id], ['id' => $second->id, 'user_id' => $second->user_id]],
        ])->assertConflict();
        $this->assertSame('Driver', $first->fresh()->role);
    }

    public function test_dispatcher_can_reassign_the_driver_without_creating_two_drivers(): void
    {
        [$team, $first, $second] = $this->team();
        $first->update(['role' => 'Driver']);
        $this->actingAs(DispatcherUser::factory()->create())->put('/dispatcher/responders/'.$team->id, [
            'team_name' => $team->team_name, 'status' => 'active', 'driver_user_id' => $second->user_id,
            'members' => [['id' => $first->id, 'user_id' => $first->user_id], ['id' => $second->id, 'user_id' => $second->user_id]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Responder', $first->fresh()->role);
        $this->assertSame('Driver', $second->fresh()->role);
        $this->assertSame(1, $team->members()->where('role', 'Driver')->count());
    }

    public function test_regular_roster_edits_preserve_selected_roles(): void
    {
        [$team, $first, $second] = $this->team();
        $first->update(['role' => 'Driver']);
        $this->actingAs(DispatcherUser::factory()->create())->put('/dispatcher/responders/'.$team->id, [
            'team_name' => 'Renamed team', 'status' => 'active',
            'members' => [['id' => $first->id, 'user_id' => $first->user_id, 'role' => 'Responder'], ['id' => $second->id, 'user_id' => $second->user_id, 'role' => 'Driver']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Driver', $first->fresh()->role);
        $this->assertSame('Responder', $second->fresh()->role);
    }

    public function test_only_the_current_driver_receives_home_dispatches_and_can_start_them(): void
    {
        [$team, $driver, $responder] = $this->team();
        $driver->update(['role' => 'Driver']);
        $report = $this->report($team, $driver->user_id);
        Sanctum::actingAs($driver->user, ['*']);
        $this->getJson('/api/driver-responder/bootstrap')->assertOk()->assertJsonPath('can_dispatch', true)->assertJsonCount(1, 'assignments');
        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', ['response_status' => 'en-route'])->assertOk();
        $report->update(['assigned_driver_id' => $responder->user_id]);
        Sanctum::actingAs($responder->user, ['*']);
        $this->getJson('/api/driver-responder/bootstrap')->assertOk()->assertJsonPath('can_dispatch', false)->assertJsonCount(0, 'assignments')->assertJsonPath('assignment', null);
        $this->getJson('/api/driver-responder/assignments')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', ['response_status' => 'on-scene'])->assertForbidden();
    }

    public function test_dispatch_selects_the_current_driver_and_stops_when_the_role_is_released(): void
    {
        [$team, $responder, $driver] = $this->team();
        $driver->update(['role' => 'Driver']);
        $report = $this->report($team);
        $service = app(DispatchService::class);
        $service->dispatch($report, $team);
        $this->assertSame($driver->user_id, $report->fresh()->assigned_driver_id);
        $report->update(['status' => 'resolved']);
        Sanctum::actingAs($driver->user, ['driver-write']);
        $this->patchJson($this->role($team), ['role' => 'Responder'])->assertOk();
        $this->assertNull($service->autoAssignTeam());
        $pending = $this->report($team);
        $this->expectException(ValidationException::class);
        $service->dispatch($pending, $team);
    }

    public function test_migration_preserves_the_assigned_driver_and_removes_legacy_duplicate_driver_roles(): void
    {
        $migration = require database_path('migrations/2026_11_09_130000_enforce_one_driver_per_team.php');
        $migration->down();
        $team = ResponseTeam::create(['team_name' => 'Legacy team', 'status' => 'active']);
        $first = DriverResponderUser::factory()->create();
        $second = DriverResponderUser::factory()->create();
        $one = $team->members()->create(['user_id' => $first->id, 'name' => $first->full_name, 'role' => 'Driver']);
        $two = $team->members()->create(['user_id' => $second->id, 'name' => $second->full_name, 'role' => 'Driver']);
        $this->report($team, $second->id);
        $migration->up();
        $this->assertSame('Responder', $one->fresh()->role);
        $this->assertSame('Driver', $two->fresh()->role);
        $this->assertTrue($team->fresh()->member_roles_enabled);
        $this->assertSame(1, $team->members()->where('role', 'Driver')->count());
    }

    public function test_staff_mobile_reassignment_uses_the_same_single_driver_rule(): void
    {
        [$team, $first, $second] = $this->team();
        $first->update(['role' => 'Driver']);
        Sanctum::actingAs(DispatcherUser::factory()->create(), ['*']);
        $this->putJson('/api/operations/teams/'.$team->id.'/members/'.$second->id, [
            'user_id' => $second->user_id, 'name' => $second->name, 'role' => 'Driver', 'status' => 'active',
        ])->assertOk();
        $this->assertSame('Responder', $first->fresh()->role);
        $this->assertSame('Driver', $second->fresh()->role);
        $this->assertSame(1, $team->members()->where('role', 'Driver')->count());
    }

    public function test_only_driver_can_start_a_service_trip_and_roles_remain_locked_during_the_trip(): void
    {
        [$team, $driver, $responder] = $this->team();
        $driver->update(['role' => 'Driver']);
        $resident = ResidentUser::factory()->accepted()->create();
        $schedule = $resident->schedules()->create([
            'service_type' => 'Ambulance Standby', 'preferred_date' => now()->toDateString(),
            'preferred_time' => '09:00', 'address' => 'Town hall', 'status' => 'confirmed', 'response_team_id' => $team->id,
        ]);
        $schedule->responders()->sync([$driver->user_id, $responder->user_id]);
        Sanctum::actingAs($responder->user, ['*']);
        $this->patchJson('/api/driver-responder/schedules/'.$schedule->id.'/status', ['status' => 'en-route'])->assertUnprocessable();
        Sanctum::actingAs($driver->user, ['*']);
        $this->patchJson('/api/driver-responder/schedules/'.$schedule->id.'/status', ['status' => 'en-route'])->assertOk();
        $this->patchJson($this->role($team), ['role' => 'Responder'])->assertConflict();
    }
}
