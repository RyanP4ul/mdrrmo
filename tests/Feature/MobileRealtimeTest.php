<?php

namespace Tests\Feature;

use App\Events\EmergencyReportUpdated;
use App\Events\VehicleLocationUpdated;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $app = app();
        $app['config']->set('broadcasting.default', 'reverb');
        $app['config']->set('broadcasting.connections.reverb.key', 'test-public-key');
        $app['config']->set('broadcasting.connections.reverb.secret', 'test-secret');
        $app['config']->set('broadcasting.connections.reverb.app_id', 'test');
        $app['config']->set('reverb.apps.apps.0', ['key' => 'test-public-key', 'secret' => 'test-secret', 'app_id' => 'test', 'options' => ['port' => 8080, 'scheme' => 'http']]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    private function fleet(DriverResponderUser $driver): Vehicle
    {
        $team = ResponseTeam::create(['team_name' => 'Team '.$driver->id, 'status' => 'active']);
        TeamMember::create(['response_team_id' => $team->id, 'user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);

        return Vehicle::create(['response_team_id' => $team->id, 'plate_number' => 'UNIT-'.$driver->id, 'vehicle_type' => 'ambulance', 'status' => 'available', 'lat' => 14.8, 'lng' => 120.9]);
    }

    public function test_mobile_reverb_configuration_exposes_only_public_values_and_device_reachable_host(): void
    {
        $driver = DriverResponderUser::factory()->create();
        Sanctum::actingAs($driver, ['session', 'driver-read']);
        $this->getJson('/api/driver-responder/realtime')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('channel', 'private-drivers.'.$driver->id)->assertJsonPath('key', 'test-public-key')->assertJsonPath('host', 'localhost')->assertJsonMissingPath('secret');
        config(['mobile.reverb_public_host' => 'reverb.example.test']);
        $this->getJson('/api/driver-responder/realtime')->assertJsonPath('host', 'reverb.example.test');
    }

    public function test_only_the_current_active_driver_can_authorize_their_private_socket_channel(): void
    {
        $driver = DriverResponderUser::factory()->create();
        Sanctum::actingAs($driver, ['session', 'driver-read']);
        $channel = 'private-drivers.'.$driver->id;
        $this->postJson('/api/driver-responder/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertOk()->assertJsonPath('auth', 'test-public-key:'.hash_hmac('sha256', '123.456:'.$channel, 'test-secret'));
        $this->postJson('/api/driver-responder/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-drivers.999999'])->assertForbidden();
        $this->postJson('/api/driver-responder/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-dispatch'])->assertForbidden();
        $this->postJson('/api/driver-responder/broadcasting/auth', ['socket_id' => 'bad-socket', 'channel_name' => $channel])->assertUnprocessable();
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['*']);
        $this->postJson('/api/driver-responder/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertForbidden();
    }

    public function test_mobile_fleet_and_location_broadcasts_are_limited_to_active_team_members(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $own = $this->fleet($driver);
        $other = DriverResponderUser::factory()->create();
        $foreign = $this->fleet($other);
        Sanctum::actingAs($driver, ['*']);
        $this->getJson('/api/driver-responder/fleet')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $channels = array_map(fn ($channel) => (string) $channel, (new VehicleLocationUpdated($own))->broadcastOn());
        $this->assertContains('private-dispatch', $channels);
        $this->assertContains('private-drivers.'.$driver->id, $channels);
        $this->assertNotContains('private-drivers.'.$other->id, $channels);
        TeamMember::where('user_id', $driver->id)->update(['status' => 'inactive']);
        $this->assertSame(['private-dispatch'], array_map(fn ($channel) => (string) $channel, (new VehicleLocationUpdated($own))->broadcastOn()));
        $this->getJson('/api/driver-responder/fleet')->assertJsonCount(0, 'data');
    }

    public function test_saved_mobile_gps_coordinates_are_broadcast_with_the_web_map_contract(): void
    {
        Event::fake([VehicleLocationUpdated::class]);
        $driver = DriverResponderUser::factory()->create();
        $vehicle = $this->fleet($driver);
        $type = IncidentType::create(['name' => 'Medical', 'description' => 'Emergency', 'priority' => 'high']);
        $report = EmergencyReport::create(['reference' => 'RPT-LIVE', 'incident_type_id' => $type->id, 'reported_by' => $driver->id, 'reported_by_type' => $driver->getMorphClass(), 'reporter_name' => $driver->full_name, 'location' => 'Town hall', 'description' => 'Emergency', 'lat' => 14.8, 'lng' => 120.9, 'status' => 'dispatched', 'priority' => 'high', 'reported_at' => now(), 'assigned_driver_id' => $driver->id, 'response_team_id' => $vehicle->response_team_id]);
        $vehicle->update(['assigned_report_id' => $report->id]);
        Sanctum::actingAs($driver, ['*']);
        $session = $this->postJson('/api/driver-responder/tracking/sessions', ['vehicle_id' => $vehicle->id, 'background' => false, 'consent' => true, 'consent_version' => '2026-11-03'])->assertCreated()->json('session_id');
        $this->postJson('/api/driver-responder/tracking/sessions/'.$session.'/ping', ['sequence' => 1, 'lat' => 14.8123, 'lng' => 120.9123, 'accuracy' => 10, 'speed' => 4, 'observed_at' => now()->toISOString()])->assertOk();
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'lat' => 14.8123, 'lng' => 120.9123]);
        Event::assertDispatched(VehicleLocationUpdated::class, function ($event) use ($driver, $vehicle): bool {
            $payload = $event->broadcastWith();

            return $payload['lat'] === 14.8123 && $payload['lng'] === 120.9123 && $payload['plate'] === $vehicle->plate_number && isset($payload['sentAt']) && in_array('private-drivers.'.$driver->id, array_map(fn ($c) => (string) $c, $event->broadcastOn()), true);
        });
        $previous = DriverResponderUser::factory()->create();
        $channels = array_map(fn ($c) => (string) $c, (new EmergencyReportUpdated($report, $previous->id))->broadcastOn());
        $this->assertContains('private-drivers.'.$previous->id, $channels);
        $this->assertContains('private-drivers.'.$driver->id, $channels);
    }
}
