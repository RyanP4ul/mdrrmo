<?php

namespace Tests\Feature;

use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\ResponseTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DispatcherShiftRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_roster_counts_each_active_member_once_and_separates_paired_solo_and_unassigned(): void
    {
        $dayDispatcher = DispatcherUser::factory()->create(['shift_team' => 'day']);

        $pairedA = DriverResponderUser::factory()->create(['shift_team' => 'day', 'status' => 'active']);
        $pairedB = DriverResponderUser::factory()->create(['shift_team' => 'day', 'status' => 'active']);
        $solo = DriverResponderUser::factory()->create(['shift_team' => 'day', 'status' => 'active']);
        $unassigned = DriverResponderUser::factory()->create(['shift_team' => 'day', 'status' => 'active']);
        DriverResponderUser::factory()->create(['shift_team' => 'day', 'status' => 'inactive']);
        DriverResponderUser::factory()->create(['shift_team' => 'night', 'status' => 'active']);

        $pairedTeam = ResponseTeam::create(['team_name' => 'Alpha Day', 'status' => 'active', 'shift_team' => 'day']);
        $pairedTeam->members()->create(['user_id' => $pairedA->id, 'name' => $pairedA->full_name, 'role' => 'Driver', 'status' => 'active']);
        $pairedTeam->members()->create(['user_id' => $pairedB->id, 'name' => $pairedB->full_name, 'role' => 'Responder', 'status' => 'active']);

        // Also put $pairedA in a solo team to verify deduplication & Paired > Solo precedence
        $backupSolo = ResponseTeam::create(['team_name' => 'Backup Day', 'status' => 'active', 'shift_team' => 'day']);
        $backupSolo->members()->create(['user_id' => $pairedA->id, 'name' => $pairedA->full_name, 'role' => 'Driver', 'status' => 'active']);

        $soloTeam = ResponseTeam::create(['team_name' => 'Scout Day', 'status' => 'active', 'shift_team' => 'day']);
        $soloTeam->members()->create(['user_id' => $solo->id, 'name' => $solo->full_name, 'role' => 'Driver', 'status' => 'active']);

        $this->actingAs($dayDispatcher)
            ->get(route('dispatcher.responders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dispatcher/Responders')
                ->where('shiftView.active', 'day')
                ->where('shiftView.dispatcherShift', 'day')
                ->where('shiftView.canEdit', true)
                ->where('shiftRoster.total', 4)
                ->where('shiftRoster.pairedCount', 2)
                ->where('shiftRoster.soloCount', 1)
                ->where('shiftRoster.unassignedCount', 1)
                ->where('shiftRoster.driversCount', 2)
                ->where('shiftRoster.respondersCount', 1)
                ->has('shiftRoster.members', 4));

        $this->assertNotNull($unassigned->id);
    }

    public function test_dispatcher_can_view_other_shift_read_only_but_cannot_mutate_it(): void
    {
        $dayDispatcher = DispatcherUser::factory()->create(['shift_team' => 'day']);
        $nightDriver = DriverResponderUser::factory()->create(['shift_team' => 'night', 'status' => 'active']);
        $nightTeam = ResponseTeam::create(['team_name' => 'Night Unit 1', 'status' => 'active', 'shift_team' => 'night']);
        $nightTeam->members()->create(['user_id' => $nightDriver->id, 'name' => $nightDriver->full_name, 'role' => 'Driver', 'status' => 'active']);

        // Switching to night view is allowed in read-only handover mode
        $this->actingAs($dayDispatcher)
            ->get(route('dispatcher.responders.index', ['shift' => 'night']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shiftView.active', 'night')
                ->where('shiftView.canEdit', false)
                ->where('shiftRoster.total', 1)
                ->where('shiftRoster.soloCount', 1)
                ->where('shiftRoster.unassignedCount', 0));

        // Attempting to edit/delete the night team or assign someone to night roster is forbidden
        $this->actingAs($dayDispatcher)
            ->put(route('dispatcher.responders.update', $nightTeam), [
                'team_name' => 'Night Unit 1',
                'status' => 'active',
                'members' => [['user_id' => $nightDriver->id]],
            ])
            ->assertForbidden();

        $this->actingAs($dayDispatcher)
            ->delete(route('dispatcher.responders.destroy', $nightTeam))
            ->assertForbidden();

        $unassigned = DriverResponderUser::factory()->create(['shift_team' => null, 'status' => 'active']);

        $this->actingAs($dayDispatcher)
            ->patch(route('dispatcher.responders.shift-roster.update', $unassigned), ['shift_team' => 'night'])
            ->assertForbidden();

        // Removing a night-shift member as a day dispatcher is also forbidden
        $this->actingAs($dayDispatcher)
            ->patch(route('dispatcher.responders.shift-roster.update', $nightDriver), ['shift_team' => null])
            ->assertForbidden();
    }

    public function test_night_dispatcher_defaults_to_night_and_can_manage_night_roster(): void
    {
        $nightDispatcher = DispatcherUser::factory()->create(['shift_team' => 'night']);
        $unassigned = DriverResponderUser::factory()->create(['shift_team' => null, 'status' => 'active']);

        $this->actingAs($nightDispatcher)
            ->get(route('dispatcher.responders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shiftView.active', 'night')
                ->where('shiftView.canEdit', true)
                ->where('shiftRoster.total', 0));

        $this->actingAs($nightDispatcher)
            ->patch(route('dispatcher.responders.shift-roster.update', $unassigned), ['shift_team' => 'night'])
            ->assertRedirect();

        $this->assertSame('night', $unassigned->fresh()->shift_team);

        // Creating a team as a night dispatcher automatically tags it 'night'
        $this->actingAs($nightDispatcher)
            ->post(route('dispatcher.responders.store'), [
                'team_name' => 'Night Solo 1',
                'status' => 'active',
                'members' => [['user_id' => $unassigned->id]],
                'driver_user_id' => $unassigned->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('response_teams', [
            'team_name' => 'Night Solo 1',
            'shift_team' => 'night',
        ]);
    }
}
