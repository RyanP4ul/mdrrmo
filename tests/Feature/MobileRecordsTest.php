<?php

namespace Tests\Feature;

use App\Models\DriverNotification;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\ServiceSchedule;
use App\Models\TeamMember;
use App\Models\Vehicle;
use App\Notifications\MobilePasswordReset;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function report($owner, array $attributes = []): EmergencyReport
    {
        $type = IncidentType::firstOrCreate(['name' => 'Medical'], ['description' => 'Medical emergency', 'priority' => 'high', 'color' => '#123456']);

        return EmergencyReport::create($attributes + [
            'reference' => 'RPT-'.Str::random(10), 'incident_type_id' => $type->id,
            'reported_by' => $owner->id, 'reported_by_type' => $owner->getMorphClass(),
            'reporter_type' => 'witness', 'reporter_name' => $owner->full_name,
            'location' => 'Town hall', 'description' => 'Emergency description',
            'lat' => 14.8, 'lng' => 120.9, 'status' => 'pending', 'priority' => 'high', 'reported_at' => now()->subHour(),
        ]);
    }

    private function vehicle(DriverResponderUser $driver): Vehicle
    {
        $team = ResponseTeam::create(['team_name' => 'Response team', 'status' => 'active']);
        TeamMember::create(['response_team_id' => $team->id, 'user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);

        return Vehicle::create(['response_team_id' => $team->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'MED-'.Str::random(5), 'status' => 'available']);
    }

    public function test_report_details_and_edits_are_owner_scoped_and_reject_stale_or_terminal_writes(): void
    {
        $owner = ResidentUser::factory()->accepted()->create();
        $report = $this->report($owner);
        $url = '/api/resident/reports/'.$report->id;
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['*']);
        $this->getJson($url)->assertNotFound();
        Sanctum::actingAs($owner, ['resident-read', 'session']);
        $original = $this->getJson($url)->assertOk()->assertJsonPath('can_edit', true)->json();
        $this->putJson($url, $original)->assertForbidden();
        Sanctum::actingAs($owner, ['*']);
        $edit = $original;
        $edit['description'] = 'Updated findings';
        $this->putJson($url, $edit)->assertOk()->assertJsonPath('description', 'Updated findings');
        $this->assertDatabaseHas('emergency_reports', ['id' => $report->id, 'description' => 'Updated findings']);
        $this->putJson($url, $original)->assertStatus(409);
        $edit = $this->getJson($url)->json();
        $report->update(['status' => 'acknowledged']);
        $this->putJson($url, $edit)->assertUnprocessable();
    }

    public function test_withdrawal_preserves_the_record_and_notifies_once_without_permitting_dispatched_withdrawal(): void
    {
        $owner = ResidentUser::factory()->accepted()->create();
        $report = $this->report($owner);
        Sanctum::actingAs($owner, ['*']);
        $url = '/api/resident/reports/'.$report->id.'/withdraw';
        $this->patchJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson($url, ['reason' => 'Duplicate phone report'])->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->patchJson($url, ['reason' => 'Retry'])->assertOk();
        $this->assertDatabaseHas('emergency_reports', ['id' => $report->id, 'withdrawal_reason' => 'Duplicate phone report', 'status' => 'invalid']);
        $this->assertDatabaseCount('resident_notifications', 1);
        $this->assertStringContainsString('withdrawn', ResidentNotification::first()->message);
        $dispatched = $this->report($owner, ['status' => 'dispatched']);
        $this->patchJson('/api/resident/reports/'.$dispatched->id.'/withdraw', ['reason' => 'Cancel'])->assertUnprocessable();
    }

    public function test_pending_schedule_details_edit_and_revision_are_enforced(): void
    {
        $owner = ResidentUser::factory()->accepted()->create();
        $schedule = ServiceSchedule::create(['resident_id' => $owner->id, 'service_type' => 'Medical Mission', 'preferred_date' => now()->addDay()->toDateString(), 'preferred_time' => '10:30', 'address' => 'Barangay hall', 'status' => 'pending']);
        $url = '/api/resident/schedules/'.$schedule->id;
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['*']);
        $this->getJson($url)->assertNotFound();
        Sanctum::actingAs($owner, ['*']);
        $data = $this->getJson($url)->assertOk()->json();
        $data['preferred_date'] = now()->addDays(2)->toDateString();
        $data['address'] = 'New hall';
        $this->putJson($url, $data)->assertOk()->assertJsonPath('address', 'New hall');
        $this->assertDatabaseHas('service_schedules', ['id' => $schedule->id, 'address' => 'New hall']);
        $this->putJson($url, $data)->assertStatus(409);
        $data['preferred_date'] = '2000-01-01';
        $this->putJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('preferred_time');
        $schedule->update(['status' => 'confirmed']);
        $data['preferred_date'] = now()->addDays(2)->toDateString();
        $this->putJson($url, $data)->assertUnprocessable();
    }

    public function test_driver_authored_reports_include_pending_and_exclude_others(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $own = $this->report($driver);
        $other = $this->report(DriverResponderUser::factory()->create());
        Sanctum::actingAs($driver, ['*']);
        $this->getJson('/api/driver-responder/authored-reports')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.0.status', 'pending');
        $this->getJson('/api/driver-responder/reports/'.$own->id)->assertOk()->assertJsonPath('can_care', false);
        $this->getJson('/api/driver-responder/reports/'.$other->id)->assertNotFound();
    }

    public function test_dispatch_to_a_driver_report_creates_a_scoped_inbox_without_a_resident_foreign_key_error(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $vehicle = $this->vehicle($driver);
        $report = $this->report($driver);
        app(DispatchService::class)->dispatch($report, $vehicle->team);
        Sanctum::actingAs($driver, ['*']);
        $data = $this->getJson('/api/driver-responder/notifications')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($report->id, $data['report_id']);
        $this->patchJson('/api/driver-responder/notifications/'.$data['id'].'/read')->assertOk();
        $this->assertNotNull(DriverNotification::first()->read_at);
        Sanctum::actingAs(DriverResponderUser::factory()->create(), ['*']);
        $this->getJson('/api/driver-responder/notifications')->assertJsonCount(0, 'data');
        $this->patchJson('/api/driver-responder/notifications/'.$data['id'].'/read')->assertNotFound();
    }

    public function test_multiple_assignments_and_observed_presence_are_returned_without_inventing_online_members(): void
    {
        $driver = DriverResponderUser::factory()->create(['contact_number' => '09171234567']);
        $vehicle = $this->vehicle($driver);
        $stale = DriverResponderUser::factory()->create();
        TeamMember::create(['response_team_id' => $vehicle->response_team_id, 'user_id' => $stale->id, 'name' => $stale->full_name, 'role' => 'Responder', 'status' => 'active']);
        $this->report($driver, ['assigned_driver_id' => $driver->id, 'response_team_id' => $vehicle->response_team_id, 'status' => 'dispatched']);
        $this->report($driver, ['assigned_driver_id' => $driver->id, 'response_team_id' => $vehicle->response_team_id, 'status' => 'dispatched']);
        Sanctum::actingAs($driver, ['*']);
        $this->getJson('/api/driver-responder/bootstrap')->assertOk()->assertJsonCount(2, 'assignments')->assertJsonPath('teams.0.online', true)->assertJsonPath('teams.0.contact', '09171234567')->assertJsonPath('teams.1.online', false);
        $this->assertNotNull($driver->fresh()->last_seen_at);
    }

    private function patientData(): array
    {
        return ['revision' => 'new', 'driver_section' => ['driverName' => 'Responder'], 'emergency_section' => ['patientName' => 'Patient', 'sex' => 'Female', 'age' => 30, 'typeOfIncident' => 'Medical', 'vitalSigns' => ['bloodPressure' => '120/80', 'pulseRate' => '80', 'respiration' => '18']]];
    }

    public function test_patient_care_is_separate_private_validated_and_protected_from_stale_overwrites(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $driver = DriverResponderUser::factory()->create();
        $report = $this->report($resident, ['assigned_driver_id' => $driver->id, 'status' => 'dispatched']);
        $url = '/api/driver-responder/reports/'.$report->id.'/patient';
        Sanctum::actingAs($driver, ['*']);
        $this->getJson($url)->assertOk()->assertJsonPath('form', null);
        $this->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('emergency_section.sex');
        $this->putJson($url, $this->patientData())->assertOk()->assertJsonPath('form.emergency_section.patientName', 'Patient');
        $this->putJson($url, $this->patientData())->assertStatus(409);
        $this->assertDatabaseCount('report_forms', 1);
        Sanctum::actingAs($resident, ['*']);
        $this->getJson('/api/resident/reports/'.$report->id)->assertOk()->assertJsonMissingPath('form')->assertJsonPath('can_care', false);
        Sanctum::actingAs(DriverResponderUser::factory()->create(), ['*']);
        $this->getJson($url)->assertNotFound();
        $this->putJson($url, $this->patientData())->assertForbidden();
    }

    public function test_trip_records_actual_times_and_reported_distance_and_denies_invalid_order_or_other_drivers(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $report = $this->report($driver, ['assigned_driver_id' => $driver->id, 'status' => 'dispatched']);
        $url = '/api/driver-responder/reports/'.$report->id.'/trip';
        $data = ['destination' => 'District hospital', 'picked_up_at' => now()->subMinutes(30)->toISOString(), 'arrived_at' => now()->subMinutes(10)->toISOString(), 'distance_km' => 8.5];
        Sanctum::actingAs($driver, ['*']);
        $this->putJson($url, $data)->assertOk()->assertJsonPath('trip.destination', 'District hospital')->assertJsonPath('trip.distance_km', 8.5);
        $this->assertDatabaseHas('dispatch_trips', ['emergency_report_id' => $report->id, 'destination' => 'District hospital']);
        $this->putJson($url, ['arrived_at' => now()->subHours(2)->toISOString()] + $data)->assertUnprocessable()->assertJsonValidationErrors('arrived_at');
        $this->putJson($url, ['picked_up_at' => null] + $data)->assertUnprocessable()->assertJsonValidationErrors('picked_up_at');
        Sanctum::actingAs(DriverResponderUser::factory()->create(), ['*']);
        $this->putJson($url, $data)->assertNotFound();
    }

    public function test_assignment_progress_records_server_events_without_fabricating_patient_pickup(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $vehicle = $this->vehicle($driver);
        $report = $this->report($driver, ['assigned_driver_id' => $driver->id, 'response_team_id' => $vehicle->response_team_id, 'status' => 'dispatched']);
        Sanctum::actingAs($driver, ['*']);
        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', ['vehicle_status' => 'en-route'])->assertOk();
        $this->assertDatabaseHas('dispatch_trips', ['emergency_report_id' => $report->id, 'vehicle_number' => null, 'driver_name' => $driver->full_name, 'picked_up_at' => null]);
        $this->assertNotNull(DB::table('dispatch_trips')->value('started_at'));
        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', ['vehicle_status' => 'on-scene'])->assertOk();
        $this->assertNotNull(DB::table('dispatch_trips')->value('on_scene_at'));
        $this->patchJson('/api/driver-responder/assignments/'.$report->id.'/status', ['vehicle_status' => 'available'])->assertOk();
        $this->getJson('/api/driver-responder/reports/'.$report->id)->assertOk()->assertJsonPath('vehicle', null)->assertJsonPath('trip.arrived_at', null);
    }

    public function test_expired_password_code_cannot_change_password_or_revoke_sessions(): void
    {
        Notification::fake();
        $user = ResidentUser::factory()->accepted()->create(['password' => 'unchanged-password']);
        $user->createToken('session', ['*']);
        $this->postJson('/api/auth/mobile/forgot-password', ['email' => $user->email])->assertOk();
        $token = null;
        Notification::assertSentTo($user, MobilePasswordReset::class, function ($notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->travel(61)->minutes();
        $this->postJson('/api/auth/mobile/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue(Hash::check('unchanged-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->travelBack();
    }

    public function test_resident_profile_update_cannot_change_role_email_or_approval(): void
    {
        $user = ResidentUser::factory()->accepted()->create();
        Sanctum::actingAs($user, ['*']);
        $this->putJson('/api/auth/mobile/account', ['name' => 'Maria Santos', 'contact' => '09171234567', 'street' => 'Main Street', 'barangay' => 'San Juan', 'city' => 'Balagtas', 'province' => 'Bulacan', 'role' => 'admin', 'registration_status' => 'declined'])->assertOk()->assertJsonPath('name', 'Maria Santos');
        $this->assertDatabaseHas('residents', ['id' => $user->id, 'first_name' => 'Maria', 'last_name' => 'Santos', 'registration_status' => 'accepted']);
        $this->putJson('/api/auth/mobile/account', ['contact' => '123'])->assertUnprocessable()->assertJsonValidationErrors('contact');
    }

    public function test_avatar_upload_is_private_and_rejects_non_images(): void
    {
        Storage::fake('local');
        $user = ResidentUser::factory()->accepted()->create();
        Sanctum::actingAs($user, ['*']);
        $this->postJson('/api/auth/mobile/avatar', ['avatar' => UploadedFile::fake()->create('script.svg', 2, 'image/svg+xml')])->assertUnprocessable();
        $image = UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $this->postJson('/api/auth/mobile/avatar', ['avatar' => $image])->assertOk();
        $this->get('/api/auth/mobile/avatar', ['Accept' => 'application/json'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotNull($user->fresh()->avatar_path);
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['*']);
        $this->getJson('/api/auth/mobile/avatar')->assertNotFound();
    }

    public function test_password_change_checks_current_password_and_revokes_other_tokens_only(): void
    {
        $user = ResidentUser::factory()->accepted()->create(['password' => 'old-password']);
        $current = $user->createToken('current', ['*']);
        $other = $user->createToken('other', ['*']);
        $data = ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->withToken($current->plainTextToken)->putJson('/api/auth/mobile/password', $data)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $data['current_password'] = 'old-password';
        $this->withToken($current->plainTextToken)->putJson('/api/auth/mobile/password', $data)->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_email_recovery_uses_expiring_single_use_codes_and_revokes_sessions(): void
    {
        Notification::fake();
        $user = ResidentUser::factory()->accepted()->create();
        $user->createToken('old', ['*']);
        $response = $this->postJson('/api/auth/mobile/forgot-password', ['email' => $user->email])->assertOk()->json();
        $this->postJson('/api/auth/mobile/forgot-password', ['email' => 'absent@example.test'])->assertOk()->assertExactJson($response);
        $token = null;
        Notification::assertSentTo($user, MobilePasswordReset::class, function ($notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/api/auth/mobile/reset-password', ['token' => 'wrong'] + $data)->assertUnprocessable();
        $this->postJson('/api/auth/mobile/reset-password', $data)->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/auth/mobile/reset-password', $data)->assertUnprocessable();
    }

    public function test_deletion_request_requires_reauthentication_preserves_records_and_can_be_cancelled(): void
    {
        $user = ResidentUser::factory()->accepted()->create(['password' => 'password']);
        $report = $this->report($user);
        Sanctum::actingAs($user, ['*']);
        $url = '/api/auth/mobile/deletion-request';
        $this->putJson($url, ['current_password' => 'wrong'])->assertUnprocessable();
        $this->putJson($url, ['current_password' => 'password', 'reason' => 'Leaving area'])->assertOk()->assertJsonPath('deletion_request.status', 'pending');
        $this->putJson($url, ['current_password' => 'password'])->assertOk();
        $this->assertDatabaseCount('account_deletion_requests', 1);
        $this->assertDatabaseHas('emergency_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('residents', ['id' => $user->id, 'status' => 'active']);
        $this->putJson($url, ['current_password' => 'password', 'cancel' => true])->assertOk()->assertJsonPath('deletion_request.status', 'cancelled');
        $this->assertSame('cancelled', DB::table('account_deletion_requests')->value('status'));
    }
}
