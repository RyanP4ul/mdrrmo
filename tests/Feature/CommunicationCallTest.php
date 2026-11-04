<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CommunicationCall;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\EmergencyReport;
use App\Models\IncidentType;
use App\Models\ResidentUser;
use App\Services\AgoraTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunicationCallTest extends TestCase
{
    use RefreshDatabase;

    private ResidentUser $resident;

    private DispatcherUser $dispatcher;

    private DriverResponderUser $responder;

    private EmergencyReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agora.app_id' => str_repeat('a', 32), 'agora.app_certificate' => str_repeat('b', 32),
            'agora.token_seconds' => 600, 'agora.call_minutes' => 120]);
        $this->resident = ResidentUser::factory()->accepted()->create();
        $this->dispatcher = DispatcherUser::factory()->create();
        $this->responder = DriverResponderUser::factory()->create();
        $type = IncidentType::create(['name' => 'Medical', 'description' => 'Emergency', 'priority' => 'high']);
        $this->report = EmergencyReport::create([
            'reference' => EmergencyReport::generateReference(), 'incident_type_id' => $type->id,
            'reported_by' => $this->resident->id, 'reported_by_type' => $this->resident->getMorphClass(),
            'reporter_name' => $this->resident->full_name, 'location' => 'Town hall', 'description' => 'Emergency',
            'lat' => 14.8, 'lng' => 120.9, 'status' => 'dispatched', 'priority' => 'high',
            'reported_at' => now(), 'assigned_driver_id' => $this->responder->id,
        ]);
    }

    private function createCall(): string
    {
        Sanctum::actingAs($this->dispatcher, ['session', 'staff-read', 'staff-write']);

        return $this->postJson('/api/communication/calls', ['report_id' => $this->report->id])
            ->assertCreated()->assertJsonCount(3, 'data.participants')->json('data.id');
    }

    public function test_each_participant_receives_a_distinct_uid_in_the_same_channel(): void
    {
        $id = $this->createCall();
        foreach ([[$this->resident, 1], [$this->dispatcher, 2], [$this->responder, 3]] as [$user, $uid]) {
            Sanctum::actingAs($user, ['*']);
            $response = $this->postJson('/api/communication/calls/'.$id.'/token')
                ->assertOk()->assertJsonPath('data.uid', $uid)->assertJsonPath('data.channel', 'mdrrmo-'.$id)
                ->assertJsonMissingPath('data.app_certificate')->assertHeader('Cache-Control', 'no-store, private');
            $this->assertStringStartsWith('007', $response->json('data.token'));
            $this->getJson('/api/communication/calls')->assertOk()->assertJsonCount(1, 'data');
        }
    }

    public function test_nonparticipants_and_admins_cannot_obtain_call_details_or_tokens(): void
    {
        $id = $this->createCall();
        foreach ([ResidentUser::factory()->accepted()->create(), DriverResponderUser::factory()->create(),
            DispatcherUser::factory()->create(), AdminUser::factory()->create()] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/communication/calls/'.$id)->assertForbidden();
            $this->postJson('/api/communication/calls/'.$id.'/token')->assertForbidden();
            $this->postJson('/api/communication/calls/'.$id.'/end')->assertForbidden();
            if (! $user->hasRole('admin')) {
                $this->getJson('/api/communication/calls')->assertOk()->assertJsonCount(0, 'data');
            }
        }
    }

    public function test_only_dispatchers_can_create_calls_and_duplicate_active_calls_are_rejected(): void
    {
        foreach ([$this->resident, $this->responder, AdminUser::factory()->create()] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->postJson('/api/communication/calls', ['report_id' => $this->report->id])->assertForbidden();
        }
        $this->createCall();
        $this->postJson('/api/communication/calls', ['report_id' => $this->report->id])->assertConflict();
        $this->postJson('/api/communication/calls', [])->assertUnprocessable();
    }

    public function test_only_owning_dispatcher_can_end_a_call_and_ending_is_idempotent(): void
    {
        $id = $this->createCall();
        Sanctum::actingAs($this->resident, ['*']);
        $this->postJson('/api/communication/calls/'.$id.'/end')->assertForbidden();
        Sanctum::actingAs($this->dispatcher, ['*']);
        $this->postJson('/api/communication/calls/'.$id.'/end')->assertOk()->assertJsonPath('data.status', 'ended');
        $ended = CommunicationCall::findOrFail($id)->ended_at;
        $this->travel(1)->minutes();
        $this->postJson('/api/communication/calls/'.$id.'/end')->assertOk();
        $this->assertTrue($ended->equalTo(CommunicationCall::findOrFail($id)->ended_at));
        $this->postJson('/api/communication/calls/'.$id.'/token')->assertConflict();
        $this->getJson('/api/communication/calls')->assertJsonCount(0, 'data');
        $this->createCall();
    }

    public function test_expired_call_cannot_issue_tokens_and_is_not_listed(): void
    {
        $id = $this->createCall();
        $this->travel(121)->minutes();
        $this->postJson('/api/communication/calls/'.$id.'/token')->assertConflict();
        $this->getJson('/api/communication/calls/'.$id)->assertJsonPath('data.status', 'expired');
        $this->getJson('/api/communication/calls')->assertJsonCount(0, 'data');
        $this->createCall();
    }

    public function test_inactive_or_reassigned_participants_and_closed_reports_block_tokens(): void
    {
        $id = $this->createCall();
        $this->responder->update(['status' => 'inactive']);
        $this->postJson('/api/communication/calls/'.$id.'/token')->assertConflict();
        $this->responder->update(['status' => 'active']);
        $replacement = DriverResponderUser::factory()->create();
        $this->report->update(['assigned_driver_id' => $replacement->id]);
        $this->postJson('/api/communication/calls/'.$id.'/token')->assertConflict();
        Sanctum::actingAs($replacement, ['*']);
        $this->postJson('/api/communication/calls/'.$id.'/token')->assertForbidden();
        Sanctum::actingAs($this->dispatcher, ['*']);
        $this->report->update(['assigned_driver_id' => $this->responder->id, 'status' => 'resolved']);
        $this->postJson('/api/communication/calls/'.$id.'/token')->assertConflict();
    }

    public function test_missing_assigned_responder_and_closed_reports_cannot_start_calls(): void
    {
        Sanctum::actingAs($this->dispatcher, ['*']);
        $this->report->update(['assigned_driver_id' => null]);
        $this->postJson('/api/communication/calls', ['report_id' => $this->report->id])->assertConflict();
        $this->report->update(['assigned_driver_id' => $this->responder->id, 'status' => 'resolved']);
        $this->postJson('/api/communication/calls', ['report_id' => $this->report->id])->assertConflict();
    }

    public function test_authentication_active_accounts_and_role_abilities_are_required(): void
    {
        $this->getJson('/api/communication/calls')->assertUnauthorized();
        $id = $this->createCall();
        Sanctum::actingAs($this->resident, ['session', 'resident-read']);
        $this->postJson('/api/communication/calls/'.$id.'/token')->assertForbidden();
        $this->getJson('/api/communication/calls/'.$id)->assertOk();
        Sanctum::actingAs($this->resident, ['resident-read', 'resident-write']);
        $this->getJson('/api/communication/calls')->assertForbidden();
        $this->resident->update(['status' => 'inactive']);
        Sanctum::actingAs($this->resident, ['*']);
        $this->getJson('/api/communication/calls')->assertUnauthorized();
    }

    public function test_missing_credentials_fail_without_creating_call_or_leaking_secrets(): void
    {
        config(['agora.app_certificate' => '']);
        Sanctum::actingAs($this->dispatcher, ['*']);
        $this->postJson('/api/communication/calls', ['report_id' => $this->report->id])
            ->assertServiceUnavailable()->assertJsonPath('message', 'Agora calling is not configured.');
        $this->assertDatabaseCount('communication_calls', 0);
    }

    public function test_browser_session_participants_can_join_without_mobile_bearer_tokens(): void
    {
        $this->actingAs($this->dispatcher);
        $id = $this->postJson('/communication/calls', ['report_id' => $this->report->id])
            ->assertCreated()->assertJsonPath('data.joinable', true)->json('data.id');
        foreach ([[$this->resident, 1], [$this->dispatcher, 2], [$this->responder, 3]] as [$user, $uid]) {
            $this->actingAs($user);
            $this->getJson('/communication/calls')->assertOk()->assertJsonCount(1, 'data');
            $this->postJson('/communication/calls/'.$id.'/token')->assertOk()->assertJsonPath('data.uid', $uid);
        }
        $this->actingAs(ResidentUser::factory()->accepted()->create());
        $this->postJson('/communication/calls/'.$id.'/token')->assertForbidden();
        $this->actingAs(DispatcherUser::factory()->create(['status' => 'inactive']));
        $this->getJson('/communication/calls')->assertForbidden();
    }

    public function test_call_details_tell_clients_to_leave_after_report_reassignment(): void
    {
        $id = $this->createCall();
        $this->getJson('/api/communication/calls/'.$id)->assertJsonPath('data.joinable', true)
            ->assertJsonPath('data.report_reference', $this->report->reference);
        $this->report->update(['assigned_driver_id' => DriverResponderUser::factory()->create()->id]);
        $this->getJson('/api/communication/calls/'.$id)->assertJsonPath('data.joinable', false);
    }

    public function test_tokens_never_outlive_the_call_and_client_cannot_override_channel_or_uid(): void
    {
        $this->freezeTime();
        $id = $this->createCall();
        CommunicationCall::findOrFail($id)->update(['expires_at' => now()->addSeconds(15)]);
        $response = $this->postJson('/api/communication/calls/'.$id.'/token', ['uid' => 0, 'channel' => 'foreign'])
            ->assertOk()->assertJsonPath('data.uid', 2)->assertJsonPath('data.channel', 'mdrrmo-'.$id)
            ->assertJsonPath('data.expires_at', now()->addSeconds(15)->toISOString());
        $raw = gzuncompress(base64_decode(substr($response->json('data.token'), 3)));
        // Signature is a 2-byte length followed by 32 bytes; then app ID length + ID.
        $this->assertSame(15, unpack('V', substr($raw, 72, 4))[1]);
    }

    public function test_access_token_binary_signature_identity_and_privileges_match_agora_format(): void
    {
        $this->freezeTime();
        $encoded = app(AgoraTokenService::class)->build('test-channel', 3, 600);
        $raw = gzuncompress(base64_decode(substr($encoded, 3)));
        $this->assertSame(32, unpack('v', substr($raw, 0, 2))[1]);
        $signature = substr($raw, 2, 32);
        $body = substr($raw, 34);
        $this->assertSame(str_repeat('a', 32), substr($body, 2, 32));
        $header = unpack('Vissued/Vttl/Vsalt/vservices', substr($body, 34, 14));
        $this->assertSame(now()->timestamp, $header['issued']);
        $this->assertSame(600, $header['ttl']);
        $this->assertSame(1, $header['services']);
        $key = hash_hmac('sha256', str_repeat('b', 32), pack('V', $header['issued']), true);
        $key = hash_hmac('sha256', $key, pack('V', $header['salt']), true);
        $this->assertSame(hash_hmac('sha256', $body, $key, true), $signature);
        $service = substr($body, 48);
        $this->assertSame(['type' => 1, 'count' => 4], unpack('vtype/vcount', substr($service, 0, 4)));
        foreach ([1, 2, 3, 4] as $offset => $privilege) {
            $this->assertSame(['id' => $privilege, 'ttl' => 600], unpack('vid/Vttl', substr($service, 4 + $offset * 6, 6)));
        }
        $this->assertSame(pack('v', 12).'test-channel'.pack('v', 1).'3', substr($service, 28));
    }
}
