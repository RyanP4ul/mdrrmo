<?php

namespace Tests\Feature;

use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Models\ResponseTeam;
use App\Models\TeamMember;
use App\Services\DispatchService;
use App\Services\EmergencyReportWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestReportTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $type = IncidentType::firstOrCreate(['name' => 'Medical emergency'], [
            'description' => 'Medical emergency', 'priority' => 'high', 'color' => '#4169e1',
        ]);

        return [
            'incident_type_id' => $type->id, 'reporter_type' => 'witness',
            'reporter_name' => 'Juan Dela Cruz', 'reporter_contact' => '09171234567',
            'location' => 'Municipal Hall, Balagtas', 'lat' => 14.8153, 'lng' => 120.9094,
            'description' => 'A person needs medical attention.',
        ];
    }

    public function test_guests_can_open_the_report_page_and_load_only_public_categories(): void
    {
        $payload = $this->payload();
        $this->get('/guest/report')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Guest/Report')->where('auth.user', null));
        $this->getJson('/api/guest/incident-types')->assertOk()
            ->assertJsonCount(1, 'incident_types')->assertJsonPath('incident_types.0.id', $payload['incident_type_id'])
            ->assertJsonMissingPath('profile')->assertJsonMissingPath('reports');
    }

    public function test_guest_submission_saves_contact_details_without_creating_an_account_or_session(): void
    {
        $payload = $this->payload();
        $payload['reporter_name'] = '  Juan   Dela Cruz  ';
        $payload['reporter_contact'] = '+63 917-123-4567';
        $response = $this->postJson('/api/guest/reports', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertEqualsCanonicalizing(['reference', 'message'], array_keys($response->json()));
        $this->assertDatabaseHas('emergency_reports', [
            'reference' => $response->json('reference'), 'reporter_name' => 'Juan Dela Cruz',
            'reporter_contact' => '+639171234567', 'reported_by' => null, 'reported_by_type' => null,
            'priority' => 'high', 'status' => 'pending', 'source' => 'mobile-app',
        ]);
        $this->assertDatabaseCount('residents', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('resident_notifications', 0);
        $this->assertGuest();
    }

    public function test_guest_retries_replay_the_receipt_and_reject_reusing_a_key_for_different_details(): void
    {
        $payload = $this->payload();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $first = $this->postJson('/api/guest/reports', $payload, $headers)->assertCreated();
        $this->postJson('/api/guest/reports', $payload, $headers)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($first->json());
        $this->postJson('/api/guest/reports', array_replace($payload, ['description' => 'Changed details']), $headers)->assertConflict();
        $this->assertDatabaseCount('emergency_reports', 1);
    }

    public function test_guests_cannot_supply_account_ownership_status_priority_or_dispatch_assignment(): void
    {
        $resident = ResidentUser::factory()->accepted()->create();
        $payload = $this->payload() + [
            'reported_by' => $resident->id, 'reported_by_type' => $resident->getMorphClass(),
            'status' => 'resolved', 'priority' => 'low', 'assigned_driver_id' => 999,
            'response_team_id' => 999, 'source' => 'emergency-call',
        ];
        $this->postJson('/api/guest/reports', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('emergency_reports', [
            'reported_by' => null, 'reported_by_type' => null, 'status' => 'pending', 'priority' => 'high',
            'assigned_driver_id' => null, 'response_team_id' => null, 'source' => 'mobile-app',
        ]);
        Sanctum::actingAs($resident, ['*']);
        $report = EmergencyReport::firstOrFail();
        $this->getJson('/api/resident/reports/'.$report->id)->assertNotFound();
        $this->getJson('/api/resident/bootstrap')->assertOk()->assertJsonCount(0, 'reports');
    }

    public function test_guest_validation_rejects_bad_identity_coordinates_and_missing_submission_keys(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/guest/reports', $payload)->assertUnprocessable()->assertJsonValidationErrors('submission_key');
        $this->postJson('/api/guest/reports', array_replace($payload, [
            'reporter_name' => ['bad input'], 'reporter_contact' => ['bad input'],
            'lat' => 91, 'lng' => -181, 'incident_type_id' => 999,
        ]), ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()
            ->assertJsonValidationErrors(['reporter_name', 'reporter_contact', 'lat', 'lng', 'incident_type_id']);
        $this->assertDatabaseCount('emergency_reports', 0);
    }

    public function test_guest_access_does_not_unlock_any_resident_or_staff_resources(): void
    {
        foreach (['/api/resident/bootstrap', '/api/resident/reports', '/api/resident/reports/1', '/api/resident/schedules', '/api/resident/notifications', '/api/auth/mobile/me', '/api/communication/calls', '/api/operations/dashboard', '/api/driver-responder/reports'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->postJson('/api/resident/schedules', [])->assertUnauthorized();
        $this->putJson('/api/resident/reports/1', [])->assertUnauthorized();
        $this->postJson('/api/communication/calls/sos', [])->assertUnauthorized();
        $this->getJson('/api/guest/reports/1')->assertNotFound();
    }

    public function test_guest_submission_limits_apply_to_public_requests(): void
    {
        $payload = $this->payload();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/guest/reports', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        }
        $this->postJson('/api/guest/reports', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertTooManyRequests();
        $this->assertDatabaseCount('emergency_reports', 5);
    }

    public function test_guest_reports_support_dispatcher_review_assignment_and_resolution(): void
    {
        $this->postJson('/api/guest/reports', $this->payload(), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $report = EmergencyReport::firstOrFail();
        $dispatcher = DispatcherUser::factory()->create();
        $this->actingAs($dispatcher)->get('/dispatcher')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dispatcher/Dashboard')->has('incoming', 1)
                ->where('incoming.0.reference', $report->reference)->where('incoming.0.reporter_contact', '09171234567'));
        $this->get('/dispatcher/reports/'.$report->id)->assertOk();
        $this->patch('/dispatcher/reports/'.$report->id.'/acknowledge')->assertRedirect();
        $this->assertSame('acknowledged', $report->fresh()->status);
        $driver = DriverResponderUser::factory()->create();
        $team = ResponseTeam::create(['team_name' => 'Guest response team', 'status' => 'active']);
        TeamMember::create(['response_team_id' => $team->id, 'user_id' => $driver->id, 'name' => $driver->full_name, 'role' => 'Driver', 'status' => 'active']);
        app(DispatchService::class)->dispatch($report, $team);
        $this->assertSame($driver->id, $report->fresh()->assigned_driver_id);
        Sanctum::actingAs($driver, ['*']);
        $this->getJson('/api/driver-responder/reports/'.$report->id)->assertOk()->assertJsonPath('reference', $report->reference);
        app(EmergencyReportWorkflow::class)->transition($report, 'resolved', $driver);
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertDatabaseCount('resident_notifications', 0);
        $this->assertDatabaseHas('driver_notifications', ['driver_id' => $driver->id, 'report_id' => $report->id]);
    }
}
