<?php

namespace Tests\Feature;

use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentNotification;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeating_a_report_submission_replays_the_saved_result_without_duplicates(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        Sanctum::actingAs($resident, ['resident-write']);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $payload = $this->reportPayload();
        $first = $this->postJson('/api/resident/reports', $payload, $headers)->assertCreated();

        $this->postJson('/api/resident/reports', $payload, $headers)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('report.id', $first->json('report.id'));

        $this->assertDatabaseCount('emergency_reports', 1);
        $this->assertDatabaseCount('resident_notifications', 1);
        $this->assertDatabaseCount('mobile_submissions', 1);
        $encrypted = DB::table('mobile_submissions')->value('encrypted_response');
        $this->assertStringNotContainsString('Town hall', $encrypted);
    }

    public function test_reusing_a_submission_key_with_different_details_returns_409(): void
    {
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['resident-write']);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $payload = $this->reportPayload();
        $this->postJson('/api/resident/reports', $payload, $headers)->assertCreated();
        $payload['description'] = 'Different incident';

        $this->postJson('/api/resident/reports', $payload, $headers)->assertConflict();

        $this->assertDatabaseCount('emergency_reports', 1);
    }

    public function test_create_requires_a_valid_submission_key_and_rejected_input_can_be_corrected(): void
    {
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['resident-write']);
        $this->postJson('/api/resident/reports', $this->reportPayload())->assertUnprocessable()->assertJsonValidationErrors('submission_key');
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $payload = $this->reportPayload();
        $payload['lat'] = 200;
        $this->postJson('/api/resident/reports', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->assertDatabaseCount('mobile_submissions', 0);
        $payload['lat'] = 14.8;

        $this->postJson('/api/resident/reports', $payload, $headers)->assertCreated();
        $this->assertDatabaseCount('emergency_reports', 1);
    }

    public function test_submission_keys_are_scoped_to_each_account(): void
    {
        $first = ResidentUser::factory()->accepted()->create();
        $second = ResidentUser::factory()->accepted()->create();
        $payload = $this->reportPayload();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        Sanctum::actingAs($first, ['resident-write']);
        $created = $this->postJson('/api/resident/reports', $payload, $headers)->assertCreated();
        Sanctum::actingAs($second, ['resident-write']);

        $other = $this->postJson('/api/resident/reports', $payload, $headers)->assertCreated();

        $this->assertNotSame($created->json('report.id'), $other->json('report.id'));
        $this->assertDatabaseCount('emergency_reports', 2);
    }

    public function test_schedule_retries_do_not_duplicate_requests_or_notifications(): void
    {
        $this->freezeTime();
        Sanctum::actingAs(ResidentUser::factory()->accepted()->create(), ['resident-write']);
        $payload = ['service_type' => 'Other', 'preferred_date' => now()->addDays(2)->toDateString(), 'preferred_time' => '15:00', 'address' => 'Town hall'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson('/api/resident/schedules', $payload, $headers)->assertCreated();

        $this->postJson('/api/resident/schedules', $payload, $headers)->assertCreated()->assertJsonPath('schedule.id', $first->json('schedule.id'));

        $this->assertDatabaseCount('service_schedules', 1);
        $this->assertDatabaseCount('resident_notifications', 1);
    }

    public function test_registration_persists_versioned_consent_and_is_safe_to_retry(): void
    {
        $payload = ['name' => 'Taylor Resident', 'contact' => '0912 345 6789', 'address' => 'Town hall',
            'email' => 'consent@example.test', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123',
            'consent' => true, 'consent_version' => config('mobile.consent_version')];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson('/api/auth/mobile/register', $payload, $headers)->assertCreated();

        $this->postJson('/api/auth/mobile/register', $payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseCount('residents', 1);
        $this->assertDatabaseCount('resident_consents', 1);
        $this->assertDatabaseHas('resident_consents', ['version' => config('mobile.consent_version'), 'statement' => config('mobile.consent_text')]);
        $this->assertDatabaseHas('residents', ['email' => 'consent@example.test', 'contact_number' => '09123456789']);
    }

    public function test_registration_rejects_missing_consent_invalid_phone_and_long_name_parts(): void
    {
        $payload = ['name' => str_repeat('A', 61).' Resident', 'contact' => '----------', 'address' => 'Town hall',
            'email' => 'invalid@example.test', 'password' => 'SecurePassword123', 'password_confirmation' => 'SecurePassword123'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson('/api/auth/mobile/register', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors(['contact', 'consent', 'consent_version']);
        $payload['contact'] = '09123456789';
        $payload['consent'] = true;
        $payload['consent_version'] = config('mobile.consent_version');
        $this->postJson('/api/auth/mobile/register', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('residents', 0);
        $this->assertDatabaseCount('resident_consents', 0);
    }

    public function test_policy_is_public_and_explains_consent_and_record_retention(): void
    {
        $this->getJson('/api/auth/mobile/policy')->assertOk()->assertJsonPath('version', config('mobile.consent_version'))
            ->assertJsonPath('consent', config('mobile.consent_text'))->assertJsonPath('privacy', config('mobile.privacy_text'));
    }

    public function test_all_resident_lists_page_without_leaking_other_accounts(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $other = ResidentUser::factory()->accepted()->create();
        $type = IncidentType::query()->create(['name' => 'Medical', 'priority' => 'high', 'description' => 'Medical emergency']);
        for ($i = 0; $i < 3; $i++) {
            $resident->reports()->create(['reference' => 'PAGE-'.$i, 'incident_type_id' => $type->id, 'location' => 'Owner', 'lat' => 14.8, 'lng' => 120.9, 'description' => 'Owner report', 'reporter_name' => $resident->full_name]);
            $resident->schedules()->create(['service_type' => 'Other', 'preferred_date' => '2030-01-01', 'preferred_time' => '09:00', 'address' => 'Owner']);
            ResidentNotification::query()->create(['user_id' => $resident->getKey(), 'type' => 'general', 'title' => 'Owner', 'message' => 'Owner']);
        }
        $other->reports()->create(['reference' => 'PRIVATE', 'incident_type_id' => $type->id, 'location' => 'Private', 'lat' => 14.8, 'lng' => 120.9, 'description' => 'Private report', 'reporter_name' => $other->full_name]);
        $other->schedules()->create(['service_type' => 'Other', 'preferred_date' => '2030-01-01', 'preferred_time' => '09:00', 'address' => 'Private']);
        ResidentNotification::query()->create(['user_id' => $other->getKey(), 'type' => 'general', 'title' => 'Private', 'message' => 'Private']);
        Sanctum::actingAs($resident, ['resident-read']);

        foreach (['reports', 'schedules', 'notifications'] as $resource) {
            $page = $this->getJson('/api/resident/'.$resource.'?limit=2')->assertOk()->assertJsonCount(2, 'data');
            $cursor = $page->json('next_before');
            $next = $this->getJson('/api/resident/'.$resource.'?limit=2&before='.$cursor)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('next_before', null);
            $this->assertStringNotContainsString('Private', $page->getContent().$next->getContent());
            $ids = [...array_column($page->json('data'), 'id'), ...array_column($next->json('data'), 'id')];
            $this->assertCount(3, array_unique($ids));
        }
        $this->getJson('/api/resident/reports?limit=1000')->assertUnprocessable();
        $this->getJson('/api/resident/reports?before=-1')->assertUnprocessable();
    }

    public function test_login_issues_scoped_expiring_tokens_and_read_only_tokens_cannot_write(): void
    {
        $this->freezeTime();
        $resident = ResidentUser::factory()->accepted()->create(['email' => 'token@example.test', 'password' => 'SecurePassword123']);
        $login = $this->postJson('/api/auth/mobile/login', ['email' => $resident->email, 'password' => 'SecurePassword123', 'device_name' => 'Test device'])->assertOk();
        $token = $resident->tokens()->firstOrFail();
        $this->assertSame(['session', 'resident-read', 'resident-write'], $token->abilities);
        $this->assertSame(now()->addMinutes(10080)->timestamp, $token->expires_at->timestamp);
        $readOnly = $resident->createToken('Read only', ['session', 'resident-read'], now()->addHour())->plainTextToken;
        $this->withToken($readOnly)->getJson('/api/resident/bootstrap')->assertOk();
        $this->withToken($readOnly)->postJson('/api/resident/reports', $this->reportPayload(), ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('emergency_reports', 0);
    }

    public function test_expired_tokens_and_suspended_accounts_cannot_restore_sessions(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $expired = $resident->createToken('Expired', ['session', 'resident-read'], now()->subMinute())->plainTextToken;
        $this->withToken($expired)->getJson('/api/auth/mobile/me')->assertUnauthorized();
        $active = $resident->createToken('Suspended account', ['session', 'resident-read'], now()->addHour())->plainTextToken;
        $resident->update(['status' => 'suspended']);
        $this->withToken($active)->getJson('/api/auth/mobile/me')->assertUnauthorized();
    }

    public function test_login_and_registration_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/mobile/login', ['email' => 'absent@example.test', 'password' => 'WrongPassword', 'device_name' => 'Test'])->assertUnauthorized();
        }
        $this->postJson('/api/auth/mobile/login', ['email' => 'absent@example.test', 'password' => 'WrongPassword', 'device_name' => 'Test'])->assertTooManyRequests()->assertHeader('Retry-After');
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/mobile/register', [], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->postJson('/api/auth/mobile/register', [], ['Idempotency-Key' => (string) Str::uuid()])->assertTooManyRequests();
    }

    public function test_vehicle_updates_require_active_driver_membership_and_explicit_selection(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::query()->create(['team_name' => 'Vehicle permissions']);
        $member = TeamMember::query()->create(['response_team_id' => $team->id, 'user_id' => $driver->getKey(), 'name' => $driver->full_name, 'role' => 'Responder', 'status' => 'active']);
        $first = Vehicle::query()->create(['response_team_id' => $team->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'FIRST', 'status' => 'available']);
        $second = Vehicle::query()->create(['response_team_id' => $team->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'SECOND', 'status' => 'available']);
        Sanctum::actingAs($driver, ['driver-read', 'driver-write']);
        $this->patchJson('/api/driver-responder/availability', ['vehicle_id' => $first->id, 'vehicle_status' => 'offline'])->assertNotFound();
        $member->update(['role' => 'Driver']);
        $this->patchJson('/api/driver-responder/availability', ['vehicle_status' => 'offline'])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');

        $this->patchJson('/api/driver-responder/availability', ['vehicle_id' => $second->id, 'vehicle_status' => 'offline'])->assertOk();

        $this->assertDatabaseHas('vehicles', ['id' => $first->id, 'status' => 'available']);
        $this->assertDatabaseHas('vehicles', ['id' => $second->id, 'status' => 'offline']);
    }

    public function test_vehicle_assigned_to_another_driver_cannot_be_edited(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $other = DriverResponderUser::factory()->create();
        $team = ResponseTeam::query()->create(['team_name' => 'Shared team']);
        TeamMember::query()->create(['response_team_id' => $team->id, 'user_id' => $driver->getKey(), 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);
        $type = IncidentType::query()->create(['name' => 'Medical', 'priority' => 'high', 'description' => 'Medical emergency']);
        $report = EmergencyReport::query()->create(['reference' => 'OTHER-ASSIGNMENT', 'incident_type_id' => $type->id, 'location' => 'Private', 'lat' => 14.8, 'lng' => 120.9, 'description' => 'Other driver', 'reporter_name' => 'Other', 'assigned_driver_id' => $other->getKey(), 'response_team_id' => $team->id, 'status' => 'dispatched']);
        $vehicle = Vehicle::query()->create(['response_team_id' => $team->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'OTHER', 'status' => 'on-scene', 'assigned_report_id' => $report->id]);
        Sanctum::actingAs($driver, ['driver-write']);

        $this->putJson('/api/driver-responder/profile', ['vehicle_id' => $vehicle->id, 'name' => 'Taylor Driver', 'contact' => '09123456789', 'vehicle_type' => 'fire-truck', 'vehicle_number' => 'CHANGED'])->assertUnprocessable();

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'plate_number' => 'OTHER']);
    }

    public function test_legacy_tokens_older_than_the_global_lifetime_are_rejected(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $issued = $resident->createToken('Legacy token', ['*']);
        $issued->accessToken->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->withToken($issued->plainTextToken)->getJson('/api/auth/mobile/me')->assertUnauthorized();
    }

    public function test_driver_history_pages_are_owned_and_have_no_old_fixed_limit(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $other = DriverResponderUser::factory()->create();
        $type = IncidentType::query()->create(['name' => 'History', 'description' => 'History records', 'priority' => 'high']);
        for ($i = 0; $i < 3; $i++) {
            EmergencyReport::query()->create(['reference' => 'DRIVER-HISTORY-'.$i, 'incident_type_id' => $type->id, 'location' => 'Mine',
                'lat' => 14.8, 'lng' => 120.9, 'description' => 'Mine', 'reporter_name' => 'Reporter', 'assigned_driver_id' => $driver->getKey(), 'status' => 'resolved']);
        }
        EmergencyReport::query()->create(['reference' => 'OTHER-HISTORY', 'incident_type_id' => $type->id, 'location' => 'Private',
            'lat' => 14.8, 'lng' => 120.9, 'description' => 'Private', 'reporter_name' => 'Reporter', 'assigned_driver_id' => $other->getKey(), 'status' => 'resolved']);
        Sanctum::actingAs($driver, ['driver-read']);

        $first = $this->getJson('/api/driver-responder/history?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $next = $this->getJson('/api/driver-responder/history?limit=2&before='.$first->json('next_before'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('next_before', null);

        $this->assertStringNotContainsString('Private', $first->getContent().$next->getContent());
    }

    public function test_driver_report_retries_preserve_one_pending_report(): void
    {
        $driver = DriverResponderUser::factory()->create();
        Sanctum::actingAs($driver, ['driver-write']);
        $payload = $this->reportPayload() + ['priority' => 'high', 'patient_name' => 'Taylor Patient', 'patient_age' => 30,
            'symptoms' => 'Difficulty breathing', 'contact_person' => 'Taylor Contact', 'contact_number' => '09123456789'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson('/api/driver-responder/reports', $payload, $headers)->assertCreated();

        $this->postJson('/api/driver-responder/reports', $payload, $headers)->assertCreated()->assertJsonPath('report.id', $first->json('report.id'));

        $this->assertDatabaseCount('emergency_reports', 1);
        $this->assertDatabaseHas('emergency_reports', ['id' => $first->json('report.id'), 'status' => 'pending']);
    }

    public function test_vehicle_identity_cannot_change_during_an_active_assignment(): void
    {
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::query()->create(['team_name' => 'Active assignment team']);
        TeamMember::query()->create(['response_team_id' => $team->id, 'user_id' => $driver->getKey(), 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);
        $type = IncidentType::query()->create(['name' => 'Active', 'description' => 'Active incident', 'priority' => 'high']);
        $report = EmergencyReport::query()->create(['reference' => 'ACTIVE-IDENTITY', 'incident_type_id' => $type->id, 'location' => 'Town hall',
            'lat' => 14.8, 'lng' => 120.9, 'description' => 'Active', 'reporter_name' => 'Reporter', 'assigned_driver_id' => $driver->getKey(), 'response_team_id' => $team->id, 'status' => 'dispatched']);
        $vehicle = Vehicle::query()->create(['response_team_id' => $team->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'IN-USE', 'status' => 'on-scene', 'assigned_report_id' => $report->id]);
        Sanctum::actingAs($driver, ['driver-write']);

        $this->putJson('/api/driver-responder/profile', ['vehicle_id' => $vehicle->id, 'name' => 'Taylor Driver', 'contact' => '09123456789', 'vehicle_type' => 'fire-truck', 'vehicle_number' => 'CHANGED'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'vehicle_type' => 'ambulance', 'plate_number' => 'IN-USE']);
        $this->assertDatabaseHas('drivers', ['id' => $driver->getKey(), 'first_name' => $driver->first_name]);
    }

    public function test_repeating_a_schedule_cancellation_does_not_duplicate_notifications(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $schedule = $resident->schedules()->create(['service_type' => 'Other', 'preferred_date' => '2030-01-01', 'preferred_time' => '09:00', 'address' => 'Town hall']);
        Sanctum::actingAs($resident, ['resident-write']);
        $this->patchJson('/api/resident/schedules/'.$schedule->id.'/cancel')->assertOk();

        $this->patchJson('/api/resident/schedules/'.$schedule->id.'/cancel')->assertOk()->assertJsonPath('schedule.status', 'cancelled');

        $this->assertDatabaseCount('resident_notifications', 1);
    }

    private function reportPayload(): array
    {
        $type = IncidentType::query()->firstOrCreate(['name' => 'Medical'], ['priority' => 'high', 'description' => 'Medical emergency']);

        return ['incident_type_id' => $type->id, 'reporter_type' => 'witness', 'location' => 'Town hall', 'lat' => 14.8, 'lng' => 120.9, 'description' => 'Medical emergency'];
    }
}
