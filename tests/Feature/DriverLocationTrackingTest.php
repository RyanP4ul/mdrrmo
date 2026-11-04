<?php

namespace Tests\Feature;

use App\Events\DriverLocationUpdated;
use App\Events\EmergencyReportUpdated;
use App\Models\DispatcherUser;
use App\Models\DriverLocation;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverLocationTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function start(): string
    {
        return $this->postJson('/api/driver-responder/location/sessions', ['background' => true, 'notice_version' => 'driver-2026-11-04'])->assertCreated()->json('session_id');
    }

    private function fix(int $sequence = 1): array
    {
        return ['sequence' => $sequence, 'lat' => 14.8123, 'lng' => 120.9123, 'accuracy' => 10, 'speed' => 4, 'heading' => 90, 'observed_at' => now()->toISOString()];
    }

    public function test_driver_coordinates_without_vehicle_or_assignment_reach_the_dispatcher_map_and_reverb_contract(): void
    {
        Event::fake([DriverLocationUpdated::class]);
        $driver = DriverResponderUser::factory()->create();
        Sanctum::actingAs($driver, ['session', 'driver-read', 'driver-write']);
        $session = $this->start();
        $this->postJson('/api/driver-responder/location/sessions/'.$session.'/ping', $this->fix())->assertOk()->assertJsonPath('accepted', true);
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseHas('driver_locations', ['driver_id' => $driver->id, 'lat' => 14.8123, 'lng' => 120.9123, 'speed' => 14]);
        Event::assertDispatched(DriverLocationUpdated::class, function (DriverLocationUpdated $event) use ($driver): bool {
            $data = $event->broadcastWith();

            return $event->broadcastAs() === 'driver.moved' && $data['id'] === $driver->id && $data['name'] === $driver->full_name
                && $data['lat'] === 14.8123 && $data['lng'] === 120.9123 && ! array_key_exists('plate', $data)
                && in_array('private-dispatch', array_map('strval', $event->broadcastOn()), true);
        });
        $this->getJson('/api/driver-responder/locations')->assertOk()->assertJsonPath('data.0.id', $driver->id);
        $this->actingAs(DispatcherUser::factory()->create())->get('/dispatcher/map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dispatcher/Map')->has('drivers', 1)->where('drivers.0.id', $driver->id)->where('drivers.0.lat', 14.8123)->missing('vehicles'));
    }

    public function test_invalid_stale_replayed_and_expired_positions_do_not_replace_accepted_coordinates(): void
    {
        $this->freezeTime();
        Event::fake([DriverLocationUpdated::class]);
        Sanctum::actingAs(DriverResponderUser::factory()->create(), ['*']);
        $session = $this->start();
        $path = '/api/driver-responder/location/sessions/'.$session.'/ping';
        $this->postJson($path, array_replace($this->fix(), ['accuracy' => 201]))->assertUnprocessable();
        $this->postJson($path, array_replace($this->fix(), ['observed_at' => now()->subMinutes(3)->toISOString()]))->assertUnprocessable();
        $this->postJson($path, $this->fix())->assertOk()->assertJsonPath('accepted', true);
        $this->postJson($path, array_replace($this->fix(), ['lat' => 0]))->assertOk()->assertJsonPath('accepted', false);
        $this->assertDatabaseHas('driver_locations', ['lat' => 14.8123]);
        $this->travel(4)->minutes();
        $this->postJson($path, $this->fix(2))->assertConflict();
        $this->getJson('/api/driver-responder/locations')->assertJsonPath('data.0.status', 'offline');
        Event::assertDispatchedTimes(DriverLocationUpdated::class, 1);
    }

    public function test_only_the_owning_token_can_send_or_stop_and_logout_expires_its_location(): void
    {
        Event::fake([DriverLocationUpdated::class]);
        $driver = DriverResponderUser::factory()->create();
        $first = $driver->createToken('phone', ['session', 'driver-write', 'driver-read'])->plainTextToken;
        $second = $driver->createToken('other', ['session', 'driver-write', 'driver-read'])->plainTextToken;
        $this->withToken($first);
        $session = $this->start();
        $this->postJson('/api/driver-responder/location/sessions/'.$session.'/ping', $this->fix())->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($second)->postJson('/api/driver-responder/location/sessions/'.$session.'/ping', $this->fix(2))->assertNotFound();
        $this->deleteJson('/api/driver-responder/location/sessions/'.$session)->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($first)->postJson('/api/auth/mobile/logout')->assertOk();
        $this->assertNotNull(DB::table('driver_location_sessions')->where('id', $session)->value('stopped_at'));
        $this->assertTrue(DriverLocation::findOrFail($driver->id)->expires_at->isPast());
        Event::assertDispatched(DriverLocationUpdated::class);
    }

    public function test_team_snapshots_and_events_include_responder_accounts_but_exclude_unrelated_drivers(): void
    {
        Event::fake([DriverLocationUpdated::class]);
        $team = ResponseTeam::create(['team_name' => 'Rescue', 'status' => 'active']);
        $drivers = DriverResponderUser::factory()->count(3)->create();
        foreach ($drivers->take(2) as $driver) {
            TeamMember::create(['response_team_id' => $team->id, 'user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Responder', 'status' => 'active']);
        }
        foreach ($drivers as $driver) {
            Sanctum::actingAs($driver, ['*']);
            $session = $this->start();
            $this->postJson('/api/driver-responder/location/sessions/'.$session.'/ping', $this->fix())->assertOk();
        }
        Sanctum::actingAs($drivers[0], ['*']);
        $this->getJson('/api/driver-responder/locations')->assertJsonCount(2, 'data')->assertJsonMissing(['id' => $drivers[2]->id]);
        $channels = array_map('strval', (new DriverLocationUpdated(DriverLocation::findOrFail($drivers[0]->id)))->broadcastOn());
        $this->assertContains('private-drivers.'.$drivers[1]->id, $channels);
        $this->assertNotContains('private-drivers.'.$drivers[2]->id, $channels);
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['*']);
        $this->postJson('/api/driver-responder/location/sessions', ['background' => false, 'notice_version' => 'driver-2026-11-04'])->assertForbidden();
        Event::assertDispatched(DriverLocationUpdated::class);
    }

    public function test_dispatch_and_response_progress_require_a_responder_account_without_any_vehicle(): void
    {
        Event::fake([DriverLocationUpdated::class, EmergencyReportUpdated::class]);
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Rescue', 'status' => 'active']);
        $member = TeamMember::create(['response_team_id' => $team->id, 'user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Responder', 'status' => 'active']);
        $type = IncidentType::create(['name' => 'Medical', 'description' => 'Emergency', 'priority' => 'high']);
        $report = EmergencyReport::create(['reference' => 'RPT-DRIVER', 'incident_type_id' => $type->id, 'reported_by' => $driver->id, 'reported_by_type' => $driver->getMorphClass(), 'reporter_name' => $driver->full_name, 'location' => 'Town hall', 'description' => 'Emergency', 'lat' => 14.8, 'lng' => 120.9, 'status' => 'pending', 'priority' => 'high', 'reported_at' => now()]);
        Sanctum::actingAs($driver, ['*']);
        app(DispatchService::class)->dispatch($report, $team, $member);
        $this->assertSame($driver->id, $report->fresh()->assigned_driver_id);
        $this->postJson('/api/driver-responder/location/sessions', ['background' => false, 'notice_version' => 'driver-2026-11-04'])->assertCreated();
        $path = '/api/driver-responder/assignments/'.$report->id.'/status';
        $this->patchJson($path, ['response_status' => 'on-scene'])->assertUnprocessable();
        $this->patchJson($path, ['response_status' => 'en-route'])->assertOk()->assertJsonPath('assignment.response_status', 'en-route');
        $this->patchJson($path, ['response_status' => 'on-scene'])->assertOk()->assertJsonPath('assignment.response_status', 'on-scene');
        $this->patchJson($path, ['response_status' => 'available'])->assertOk()->assertJsonPath('assignment', null);
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertDatabaseCount('vehicles', 0);
        Event::assertDispatched(EmergencyReportUpdated::class);
    }
}
