<?php

namespace Tests\Feature;

use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DispatcherTeamMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_selection_skips_unlinked_and_inactive_driver_rosters(): void
    {
        $legacy = ResponseTeam::create(['team_name' => 'Legacy', 'status' => 'active']);
        $legacy->members()->create(['name' => 'Typed name', 'status' => 'active']);
        $inactive = DriverResponderUser::factory()->create(['status' => 'inactive']);
        $legacy->members()->create(['user_id' => $inactive->id, 'name' => $inactive->full_name, 'status' => 'active']);

        $service = app(DispatchService::class);
        $this->assertNull($service->autoAssignTeam());

        $driver = DriverResponderUser::factory()->create();
        $eligible = ResponseTeam::create(['team_name' => 'Eligible', 'status' => 'active']);
        $eligible->members()->create(['user_id' => $driver->id, 'name' => $driver->full_name, 'status' => 'active']);

        $this->assertSame($eligible->id, $service->autoAssignTeam()->id);
    }

    public function test_failed_create_and_dispatch_returns_a_form_error_without_leaving_a_report(): void
    {
        $type = IncidentType::create(['name' => 'Medical', 'description' => 'Medical emergency', 'priority' => 'high']);
        $team = ResponseTeam::create(['team_name' => 'Legacy', 'status' => 'active']);
        $team->members()->create(['name' => 'Typed name', 'status' => 'active']);

        $this->actingAs(DispatcherUser::factory()->create())->post(route('dispatcher.dispatch.store'), [
            'incident_type_id' => $type->id, 'reporter_name' => 'Caller', 'reporter_contact' => '09123456789',
            'location' => 'Town hall', 'lat' => 14.6, 'lng' => 121, 'reporter_type' => 'victim',
        ])->assertSessionHasErrors('team')->assertRedirect();

        $this->assertDatabaseCount('emergency_reports', 0);
    }

    public function test_form_lists_all_driver_responder_accounts_only(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $inactive = DriverResponderUser::factory()->create(['status' => 'inactive']);
        ResidentUser::factory()->create();

        $this->actingAs(DispatcherUser::factory()->create())
            ->get(route('dispatcher.responders.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Dispatcher/Responders')
                ->has('drivers', 2)
                ->where('drivers', fn ($drivers) => collect($drivers)->pluck('id')->sort()->values()->all() === collect([$driver->id, $inactive->id])->sort()->values()->all()));
    }

    public function test_creation_uses_the_selected_account_name_and_role(): void
    {
        $driver = DriverResponderUser::factory()->create();

        $this->actingAs(DispatcherUser::factory()->create())->post(route('dispatcher.responders.store'), [
            'team_name' => 'Alpha', 'status' => 'active',
            'members' => [['user_id' => $driver->id, 'name' => 'Forged name', 'role' => 'Forged role']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('team_members', [
            'user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver/Responder',
        ]);
    }

    public function test_editing_links_a_legacy_member_to_the_selected_account_and_keeps_the_member_id(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Alpha', 'status' => 'active']);
        $member = $team->members()->create(['name' => 'Legacy name', 'role' => 'Medic']);

        $this->actingAs(DispatcherUser::factory()->create())->put(route('dispatcher.responders.update', $team), [
            'team_name' => 'Alpha', 'status' => 'active',
            'members' => [['id' => $member->id, 'user_id' => $driver->id]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('team_members', [
            'id' => $member->id, 'user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver/Responder',
        ]);
    }

    public function test_creation_rejects_missing_non_driver_and_duplicate_accounts(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $resident = ResidentUser::factory()->create();
        $driver = DriverResponderUser::factory()->create();

        foreach ([
            [['name' => 'Typed name']],
            [['user_id' => $resident->id]],
            [['user_id' => $driver->id], ['user_id' => $driver->id]],
        ] as $members) {
            $this->actingAs($dispatcher)->post(route('dispatcher.responders.store'), [
                'team_name' => 'Invalid team', 'status' => 'active', 'members' => $members,
            ])->assertSessionHasErrors('members.0.user_id');
        }

        $this->assertDatabaseCount('response_teams', 0);
    }
}
