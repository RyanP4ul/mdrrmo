<?php

namespace Tests\Feature;

use App\Events\VehicleLocationUpdated;
use App\Models\AdminUser;
use App\Models\DispatcherUser;
use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\Shift;
use App\Models\TeamMember;
use App\Models\Vehicle;
use App\Services\AutomaticDispatch;
use App\Services\EmergencyReportWorkflow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileOptionalFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function report($owner, array $attributes = []): EmergencyReport
    {
        $type = IncidentType::firstOrCreate(['name' => 'Medical'], ['description' => 'Medical emergency', 'priority' => 'high', 'color' => '#123456']);

        return EmergencyReport::create($attributes + ['reference' => 'RPT-'.Str::random(8), 'incident_type_id' => $type->id, 'reported_by' => $owner->id, 'reported_by_type' => $owner->getMorphClass(), 'reporter_name' => $owner->full_name, 'location' => 'Town hall', 'description' => 'Medical emergency', 'lat' => 14.8, 'lng' => 120.9, 'status' => 'pending', 'priority' => 'high', 'reported_at' => now()]);
    }

    private function fleet(): array
    {
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Alpha '.Str::random(4), 'status' => 'active', 'specializations' => ['Medical']]);
        $member = TeamMember::create(['response_team_id' => $team->id, 'user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);
        $shift = Shift::create(['response_team_id' => $team->id, 'start_time' => now()->subHour(), 'end_time' => now()->addHour(), 'status' => 'on-shift']);
        $shift->members()->attach($member);
        $vehicle = Vehicle::create(['response_team_id' => $team->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'MED-'.Str::random(4), 'status' => 'available']);

        return [$driver, $team, $member, $shift, $vehicle];
    }

    public function test_automatic_location_sharing_binds_the_notice_to_the_current_dispatch(): void
    {
        Event::fake([VehicleLocationUpdated::class]);
        [$driver, $team, $member, $shift, $vehicle] = $this->fleet();
        $report = $this->report($driver, ['assigned_driver_id' => $driver->id, 'response_team_id' => $team->id, 'status' => 'dispatched']);
        $vehicle->update(['assigned_report_id' => $report->id]);
        Sanctum::actingAs($driver, ['*']);
        $payload = ['vehicle_id' => $vehicle->id, 'report_id' => $report->id, 'background' => true, 'sharing_mode' => 'automatic', 'notice_version' => '2026-11-04'];
        $this->postJson('/api/driver-responder/tracking/sessions', array_diff_key($payload, ['notice_version' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('notice_version');
        $this->postJson('/api/driver-responder/tracking/sessions', array_replace($payload, ['report_id' => $report->id + 100]))->assertConflict();
        $session = $this->postJson('/api/driver-responder/tracking/sessions', $payload)->assertCreated()->json('session_id');
        $this->assertDatabaseHas('vehicle_tracking_sessions', ['id' => $session, 'emergency_report_id' => $report->id, 'consent_version' => 'automatic:2026-11-04']);
        $this->postJson('/api/driver-responder/tracking/sessions/'.$session.'/ping', ['sequence' => 1, 'lat' => 14.81, 'lng' => 120.91, 'accuracy' => 10, 'observed_at' => now()->toIso8601String()])->assertOk()->assertJsonPath('accepted', true);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'lat' => 14.81, 'lng' => 120.91]);
        Event::assertDispatched(VehicleLocationUpdated::class);
        $report->update(['status' => 'resolved']);
        $this->postJson('/api/driver-responder/tracking/sessions/'.$session.'/ping', ['sequence' => 2, 'lat' => 14.82, 'lng' => 120.92, 'accuracy' => 10, 'observed_at' => now()->toIso8601String()])->assertConflict();
        $this->postJson('/api/driver-responder/tracking/sessions', $payload)->assertUnprocessable();
    }

    public function test_staff_login_issues_distinct_admin_and_dispatcher_scopes(): void
    {
        foreach ([AdminUser::class => true, DispatcherUser::class => false] as $class => $admin) {
            $user = $class::factory()->create(['password' => 'password']);
            $token = $this->postJson('/api/auth/mobile/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Staff mobile'])->assertOk()->json('token');
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson('/api/operations/dashboard')->assertOk()->assertJsonPath('role', $user->role);
            $this->assertTrue($user->tokens()->first()->can('staff-read'));
            $this->assertSame($admin, $user->tokens()->first()->can('admin-manage'));
        }
    }

    public function test_staff_routes_reject_residents_and_read_only_writes_and_dispatcher_admin_actions(): void
    {
        $this->getJson('/api/operations/reports')->assertUnauthorized();
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['*']);
        $this->getJson('/api/operations/reports')->assertForbidden();
        $staff = DispatcherUser::factory()->create();
        Sanctum::actingAs($staff, ['session', 'staff-read']);
        $this->postJson('/api/operations/teams', [])->assertForbidden();
        Sanctum::actingAs($staff, ['*']);
        $this->getJson('/api/operations/accounts?role=resident')->assertForbidden();
        $this->putJson('/api/operations/automatic-dispatch', ['enabled' => true, 'current_password' => 'password'])->assertForbidden();
        $this->postJson('/api/operations/incident-types', [])->assertForbidden();
    }

    public function test_search_and_local_date_filters_reach_older_owned_reports_without_exposing_others(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $wanted = $this->report($resident, ['location' => 'Old market', 'reported_at' => '2030-01-01 16:00:00']);
        $this->report($resident, ['location' => 'Old market', 'reported_at' => '2030-01-01 15:59:59']);
        $this->report(ResidentUser::factory()->accepted()->create(), ['location' => 'Old market', 'reported_at' => '2030-01-01 17:00:00']);
        $this->report($resident, ['location' => 'Another place', 'reported_at' => '2030-01-01 17:00:00']);
        Sanctum::actingAs($resident, ['*']);
        $this->getJson('/api/resident/reports?search=OLD&from=2030-01-02&to=2030-01-02&limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id)->assertJsonPath('next_before', null);
        $this->getJson('/api/resident/reports?from=2030-01-02&to=2030-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/resident/reports?from=not-a-date')->assertUnprocessable();
    }

    public function test_automatic_dispatch_is_off_until_admin_enables_it_with_their_password(): void
    {
        [$driver, $team, $member] = $this->fleet();
        $member->update(['role' => 'Responder']);
        Vehicle::query()->delete();
        $report = $this->report(ResidentUser::factory()->accepted()->create());
        $this->assertSame(0, app(AutomaticDispatch::class)->drain());
        Sanctum::actingAs(AdminUser::factory()->create(['password' => 'admin-password']), ['*']);
        $this->putJson('/api/operations/automatic-dispatch', ['enabled' => true, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->putJson('/api/operations/automatic-dispatch', ['enabled' => true, 'current_password' => 'admin-password'])->assertOk();
        $this->postJson('/api/operations/automatic-dispatch/retry')->assertOk()->assertJsonPath('dispatched', 1);
        $this->assertSame('dispatched', $report->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AUTO_ASSIGN_TEAM', 'user_name' => 'System', 'user_id' => null]);
    }

    public function test_automatic_dispatch_uses_priority_then_oldest_and_never_double_allocates(): void
    {
        [$driver,$team,$member,$shift,$vehicle] = $this->fleet();
        $resident = ResidentUser::factory()->accepted()->create();
        $low = $this->report($resident, ['priority' => 'low', 'reported_at' => now()->subHours(2)]);
        $critical = $this->report($resident, ['priority' => 'critical']);
        DB::table('automatic_dispatch_settings')->update(['enabled' => true]);
        $this->assertSame(1, app(AutomaticDispatch::class)->drain());
        $this->assertSame('dispatched', $critical->fresh()->status);
        $this->assertSame($driver->id, $critical->fresh()->assigned_driver_id);
        $this->assertSame('pending', $low->fresh()->status);
        $this->assertNull($vehicle->fresh()->assigned_report_id);
        $this->assertSame(0, app(AutomaticDispatch::class)->drain());
        $this->assertDatabaseCount('driver_notifications', 1);
        $this->assertDatabaseHas('automatic_dispatch_attempts', ['emergency_report_id' => $low->id, 'state' => 'waiting']);
    }

    public function test_automatic_dispatch_requires_capability_current_shift_and_free_driver(): void
    {
        [$driver,$team,$member,$shift,$vehicle] = $this->fleet();
        $report = $this->report(ResidentUser::factory()->accepted()->create());
        DB::table('automatic_dispatch_settings')->update(['enabled' => true]);
        $team->update(['specializations' => ['Fire']]);
        $this->assertFalse(app(AutomaticDispatch::class)->attempt($report->id));
        $team->update(['specializations' => ['Medical']]);
        $shift->update(['end_time' => now()->subMinute()]);
        $this->assertFalse(app(AutomaticDispatch::class)->attempt($report->id));
        $shift->update(['end_time' => now()->addHour()]);
        $busy = $this->report($driver, ['assigned_driver_id' => $driver->id, 'status' => 'dispatched']);
        $this->assertFalse(app(AutomaticDispatch::class)->attempt($report->id));
        $busy->update(['status' => 'resolved']);
        $this->assertTrue(app(AutomaticDispatch::class)->attempt($report->id));
    }

    public function test_unmatched_high_priority_reports_do_not_permanently_block_new_eligible_reports(): void
    {
        $this->fleet();
        $resident = ResidentUser::factory()->accepted()->create();
        $type = IncidentType::create(['name' => 'Unmatched hazard', 'description' => 'Requires another capability', 'priority' => 'critical']);
        $this->report($resident, ['incident_type_id' => $type->id, 'priority' => 'critical']);
        $eligible = $this->report($resident, ['priority' => 'low']);
        DB::table('automatic_dispatch_settings')->update(['enabled' => true]);
        $this->assertSame(0, app(AutomaticDispatch::class)->drain(1));
        $this->assertSame(1, app(AutomaticDispatch::class)->drain(1));
        $this->assertSame('dispatched', $eligible->fresh()->status);
    }

    public function test_staff_schedule_review_is_transactional_and_terminal_states_cannot_be_reopened(): void
    {
        $this->travelTo(Carbon::parse('2030-01-02 08:00', 'Asia/Manila'));
        [, $team] = $this->fleet();
        $resident = ResidentUser::factory()->accepted()->create();
        $schedule = ServiceSchedule::create(['resident_id' => $resident->id, 'service_type' => 'Medical Mission', 'preferred_date' => now(config('mobile.timezone'))->toDateString(), 'preferred_time' => '10:00', 'address' => 'Hall', 'status' => 'pending']);
        Sanctum::actingAs(DispatcherUser::factory()->create(), ['*']);
        $url = '/api/operations/schedules/'.$schedule->id;
        $this->patchJson($url, ['decision' => 'declined'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($url, ['decision' => 'confirmed', 'team_id' => $team->id])->assertOk()->assertJsonPath('status', 'confirmed');
        $this->patchJson($url, ['decision' => 'confirmed', 'team_id' => $team->id])->assertOk();
        $this->assertDatabaseCount('resident_notifications', 1);
        $this->patchJson($url, ['decision' => 'completed'])->assertOk();
        $this->patchJson($url, ['decision' => 'confirmed', 'team_id' => $team->id])->assertUnprocessable();
        $this->assertDatabaseHas('service_schedules', ['id' => $schedule->id, 'status' => 'completed']);
    }

    public function test_automatic_dispatch_waits_while_the_team_is_handling_a_scheduled_service(): void
    {
        [$driver, $team] = $this->fleet();
        $resident = ResidentUser::factory()->accepted()->create();
        $schedule = $resident->schedules()->create([
            'service_type' => 'Medical Mission', 'preferred_date' => now(config('mobile.timezone'))->toDateString(), 'preferred_time' => '10:00',
            'address' => 'Town hall', 'status' => 'en-route', 'response_team_id' => $team->id,
        ]);
        DriverNotification::scheduleReceipt($driver->id, $schedule, $team->team_name);
        $report = $this->report($resident);
        DB::table('automatic_dispatch_settings')->update(['enabled' => true]);

        $this->assertSame(0, app(AutomaticDispatch::class)->drain());
        $this->assertDatabaseHas('emergency_reports', ['id' => $report->id, 'status' => 'pending']);
        $this->assertDatabaseHas('automatic_dispatch_attempts', ['emergency_report_id' => $report->id, 'state' => 'waiting']);
        $schedule->update(['status' => 'completed']);
        $this->assertSame(1, app(AutomaticDispatch::class)->drain());
        $this->assertDatabaseHas('emergency_reports', ['id' => $report->id, 'status' => 'dispatched', 'assigned_driver_id' => $driver->id]);
    }

    public function test_admin_accounts_and_resident_review_use_role_tables_and_keep_self_active(): void
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin, ['*']);
        $body = ['first_name' => 'Taylor', 'last_name' => 'Driver', 'email' => 'new-driver@example.test', 'contact_number' => '09171234567', 'role' => 'driver/responder', 'status' => 'active', 'password' => 'password-123'];
        $driver = $this->postJson('/api/operations/accounts', $body)->assertCreated()->json();
        $this->assertDatabaseHas('drivers', ['id' => $driver['id'], 'email' => $body['email']]);
        $this->getJson('/api/operations/accounts?role=driver%2Fresponder&search=Taylor')->assertOk()->assertJsonCount(1, 'data');
        $resident = ResidentUser::factory()->create(['registration_status' => 'pending']);
        $this->patchJson('/api/operations/accounts/'.$resident->account_key.'/review', ['decision' => 'accepted'])->assertOk()->assertJsonPath('registration_status', 'accepted');
        $this->assertDatabaseHas('resident_notifications', ['user_id' => $resident->id, 'title' => 'Registration accepted']);
        $self = ['first_name' => $admin->first_name, 'last_name' => $admin->last_name, 'email' => $admin->email, 'contact_number' => '09171234567', 'role' => 'admin', 'status' => 'suspended'];
        $this->putJson('/api/operations/accounts/'.$admin->account_key, $self)->assertUnprocessable();
    }

    public function test_team_rosters_reject_cross_team_members_and_vehicle_management_is_removed(): void
    {
        [$driver,$team,$member,$shift,$vehicle] = $this->fleet();
        [$other,$otherTeam,$otherMember] = $this->fleet();
        Sanctum::actingAs(DispatcherUser::factory()->create(), ['*']);
        $this->postJson('/api/operations/teams/'.$team->id.'/shifts', ['start_time' => now()->toISOString(), 'end_time' => now()->addHour()->toISOString(), 'status' => 'on-shift', 'member_ids' => [$otherMember->id]])->assertUnprocessable()->assertJsonValidationErrors('member_ids.0');
        $this->postJson('/api/operations/teams/'.$team->id.'/shifts', ['start_time' => now()->toISOString(), 'end_time' => now()->addHour()->toISOString(), 'status' => 'on-shift', 'member_ids' => [$member->id]])->assertCreated();
        $report = $this->report($driver, ['assigned_driver_id' => $driver->id, 'response_team_id' => $team->id, 'status' => 'dispatched']);
        $vehicle->update(['assigned_report_id' => $report->id]);
        $this->putJson('/api/operations/vehicles/'.$vehicle->id, ['response_team_id' => $otherTeam->id, 'plate_number' => 'CHANGED', 'vehicle_type' => 'ambulance', 'status' => 'available'])->assertNotFound();
        $this->assertSame($team->id, $vehicle->fresh()->response_team_id);
    }

    public function test_admin_category_edits_are_shared_and_used_types_cannot_be_deleted(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(), ['*']);
        $data = ['name' => 'New hazard', 'description' => 'Hazard category', 'priority' => 'critical', 'color' => '#abcdef'];
        $id = $this->postJson('/api/operations/incident-types', $data)->assertCreated()->json('id');
        $this->putJson('/api/operations/incident-types/'.$id, ['color' => '#000000'] + $data)->assertOk()->assertJsonPath('color', '#000000');
        $resident = ResidentUser::factory()->accepted()->create();
        $this->report($resident, ['incident_type_id' => $id]);
        $this->deleteJson('/api/operations/incident-types/'.$id)->assertUnprocessable();
        $unused = $this->postJson('/api/operations/incident-types', ['name' => 'Unused'] + $data)->assertCreated()->json('id');
        $this->deleteJson('/api/operations/incident-types/'.$unused)->assertOk();
        Sanctum::actingAs($resident, ['*']);
        $response = $this->getJson('/api/resident/bootstrap')->assertOk();
        $this->assertTrue(collect($response->json('incident_types'))->contains(fn ($type) => $type['id'] === $id && $type['color'] === '#000000'));
    }

    public function test_gps_sessions_are_bound_to_the_issuing_token_and_logout_stops_them(): void
    {
        [$driver,$team,$member,$shift,$vehicle] = $this->fleet();
        $report = $this->report($driver, ['assigned_driver_id' => $driver->id, 'response_team_id' => $team->id, 'status' => 'dispatched']);
        $vehicle->update(['assigned_report_id' => $report->id]);
        $first = $driver->createToken('one', ['session', 'driver-write'])->plainTextToken;
        $second = $driver->createToken('two', ['session', 'driver-write'])->plainTextToken;
        $session = $this->withToken($first)->postJson('/api/driver-responder/tracking/sessions', ['vehicle_id' => $vehicle->id, 'background' => false, 'consent' => true, 'consent_version' => '2026-11-03'])->assertCreated()->json('session_id');
        app('auth')->forgetGuards();
        $this->withToken($second)->postJson('/api/driver-responder/tracking/sessions/'.$session.'/ping', ['sequence' => 1, 'lat' => 14.8, 'lng' => 120.9, 'accuracy' => 10, 'observed_at' => now()->toISOString()])->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($first)->postJson('/api/auth/mobile/logout')->assertOk();
        $this->assertNotNull(DB::table('vehicle_tracking_sessions')->where('id', $session)->value('stopped_at'));
    }

    private function tracking(): array
    {
        [$driver,$team,$member,$shift,$vehicle] = $this->fleet();
        $report = $this->report($driver, ['assigned_driver_id' => $driver->id, 'response_team_id' => $team->id, 'status' => 'dispatched']);
        $vehicle->update(['assigned_report_id' => $report->id]);
        Sanctum::actingAs($driver, ['*']);
        $session = $this->postJson('/api/driver-responder/tracking/sessions', ['vehicle_id' => $vehicle->id, 'background' => true, 'consent' => true, 'consent_version' => '2026-11-03'])->assertCreated()->json('session_id');

        return [$driver, $vehicle, $report, $session];
    }

    public function test_gps_pings_update_the_shared_vehicle_and_reject_replays_and_other_accounts(): void
    {
        [$driver,$vehicle,$report,$session] = $this->tracking();
        Event::fake([VehicleLocationUpdated::class]);
        $body = ['sequence' => 2, 'lat' => 14.81, 'lng' => 120.91, 'accuracy' => 15, 'speed' => 5, 'heading' => 90, 'observed_at' => now()->toISOString()];
        $url = '/api/driver-responder/tracking/sessions/'.$session.'/ping';
        $this->postJson($url, $body)->assertOk()->assertJsonPath('accepted', true);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'lat' => 14.81, 'lng' => 120.91, 'speed' => 18]);
        Event::assertDispatched(VehicleLocationUpdated::class);
        $this->postJson($url, ['sequence' => 1, 'lat' => 10] + $body)->assertOk()->assertJsonPath('accepted', false);
        $this->postJson($url, ['sequence' => 3, 'lat' => 10, 'observed_at' => now()->subMinute()->toISOString()] + $body)->assertOk()->assertJsonPath('accepted', false);
        $this->assertSame(14.81, $vehicle->fresh()->lat);
        Sanctum::actingAs(DriverResponderUser::factory()->create(), ['*']);
        $this->postJson($url, $body)->assertNotFound();
    }

    public function test_gps_requires_consent_active_assignment_and_recent_accurate_coordinates(): void
    {
        [$driver,$vehicle,$report,$session] = $this->tracking();
        $this->postJson('/api/driver-responder/tracking/sessions', ['vehicle_id' => $vehicle->id, 'background' => false])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $body = ['sequence' => 1, 'lat' => 14.81, 'lng' => 120.91, 'accuracy' => 15, 'observed_at' => now()->subMinutes(3)->toISOString()];
        $url = '/api/driver-responder/tracking/sessions/'.$session.'/ping';
        $this->postJson($url, $body)->assertUnprocessable();
        $this->postJson($url, ['accuracy' => 500, 'observed_at' => now()->toISOString()] + $body)->assertUnprocessable()->assertJsonValidationErrors('accuracy');
        $this->deleteJson('/api/driver-responder/tracking/sessions/'.$session)->assertOk();
        $this->postJson($url, ['observed_at' => now()->toISOString()] + $body)->assertStatus(409);
        $this->assertNull($vehicle->fresh()->lat);
    }

    public function test_tracking_lease_expiry_and_dispatch_completion_stop_sharing(): void
    {
        [$driver,$vehicle,$report,$session] = $this->tracking();
        $this->travel(4)->minutes();
        $this->postJson('/api/driver-responder/tracking/sessions/'.$session.'/ping', ['sequence' => 1, 'lat' => 14.8, 'lng' => 120.9, 'accuracy' => 10, 'observed_at' => now()->toISOString()])->assertStatus(409);
        $this->travelBack();
        app(EmergencyReportWorkflow::class)->transition($report, 'resolved', $driver);
        $this->assertNotNull(DB::table('vehicle_tracking_sessions')->where('id', $session)->value('stopped_at'));
    }
}
