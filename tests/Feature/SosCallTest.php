<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommunicationCall;
use App\Models\DispatcherUser;
use App\Models\DriverResponderUser;
use App\Models\ResidentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SosCallTest extends TestCase
{
    use RefreshDatabase;

    private function callers(): array
    {
        config(['agora.app_id' => str_repeat('a', 32), 'agora.app_certificate' => str_repeat('b', 32), 'agora.sos_ring_seconds' => 120, 'agora.sos_queue_seconds' => 120]);
        $resident = ResidentUser::factory()->accepted()->create();
        $dispatcher = DispatcherUser::factory()->create();
        Sanctum::actingAs($resident, ['*']);

        return [$resident, $dispatcher];
    }

    public function test_resident_calls_dispatcher_without_a_report_and_the_first_dispatcher_answers_exclusively(): void
    {
        [$resident, $dispatcher] = $this->callers();
        $other = DispatcherUser::factory()->create();
        $call = $this->postJson('/api/communication/calls/sos')->assertCreated()
            ->assertJsonPath('data.kind', 'sos')->assertJsonPath('data.report_id', null)
            ->assertJsonPath('data.resident_name', $resident->full_name)->assertJsonCount(2, 'data.participants')->json('data');
        $url = '/api/communication/calls/'.$call['id'];
        $this->postJson($url.'/token')->assertOk()->assertJsonPath('data.uid', 1)->assertJsonPath('data.channel', 'mdrrmo-'.$call['id'])->assertJsonMissingPath('data.app_certificate');
        $this->assertDatabaseCount('emergency_reports', 0);
        $this->actingAs($dispatcher)->getJson('/communication/calls')->assertOk()->assertJsonPath('data.0.id', $call['id']);
        $this->postJson('/communication/calls/'.$call['id'].'/token')->assertOk()->assertJsonPath('data.uid', 2)->assertJsonPath('data.channel', 'mdrrmo-'.$call['id']);
        $this->assertDatabaseHas('communication_calls', ['id' => $call['id'], 'dispatcher_key' => Account::keyFor($dispatcher), 'responder_key' => null]);
        $this->assertNotNull(CommunicationCall::find($call['id'])->accepted_at);
        $this->actingAs($other)->getJson('/communication/calls')->assertJsonCount(0, 'data');
        $this->postJson('/communication/calls/'.$call['id'].'/token')->assertForbidden();
        Sanctum::actingAs(DriverResponderUser::factory()->create(), ['*']);
        $this->postJson($url.'/token')->assertForbidden();
    }

    public function test_duplicate_sos_taps_resume_one_active_call_and_the_resident_can_cancel_it(): void
    {
        [$resident, $dispatcher] = $this->callers();
        $id = $this->postJson('/api/communication/calls/sos')->assertCreated()->json('data.id');
        $this->postJson('/api/communication/calls/sos')->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('communication_calls', 1);
        $this->postJson('/api/communication/calls/'.$id.'/end')->assertOk()->assertJsonPath('data.status', 'ended');
        $this->postJson('/api/communication/calls/'.$id.'/token')->assertConflict();
        $next = $this->postJson('/api/communication/calls/sos')->assertCreated()->json('data.id');
        $this->assertNotSame($id, $next);
        Sanctum::actingAs($dispatcher, ['*']);
        $this->postJson('/api/communication/calls/'.$next.'/end')->assertForbidden();
        $this->postJson('/api/communication/calls/'.$next.'/token')->assertOk();
        $this->postJson('/api/communication/calls/'.$next.'/end')->assertOk()->assertJsonPath('data.status', 'ended');
    }

    public function test_sos_requires_an_approved_resident_write_access_and_configured_agora(): void
    {
        [$resident, $dispatcher] = $this->callers();
        Sanctum::actingAs(ResidentUser::factory()->create(), ['*']);
        $this->postJson('/api/communication/calls/sos')->assertForbidden();
        Sanctum::actingAs($dispatcher, ['*']);
        $this->postJson('/api/communication/calls/sos')->assertForbidden();
        Sanctum::actingAs($resident, ['resident-read']);
        $this->postJson('/api/communication/calls/sos')->assertForbidden();
        Sanctum::actingAs($resident, ['*']);
        config(['agora.app_certificate' => '']);
        $this->postJson('/api/communication/calls/sos')->assertServiceUnavailable()->assertJsonPath('message', 'Agora calling is not configured.');
        $this->assertDatabaseCount('communication_calls', 0);
    }

    public function test_unavailable_dispatch_and_expired_calls_do_not_claim_a_connection(): void
    {
        [$resident, $dispatcher] = $this->callers();
        $dispatcher->update(['status' => 'inactive']);
        $this->postJson('/api/communication/calls/sos')->assertServiceUnavailable();
        $dispatcher->update(['status' => 'active']);
        $id = $this->postJson('/api/communication/calls/sos')->assertCreated()->json('data.id');
        $this->travel(121)->seconds();
        Sanctum::actingAs($dispatcher, ['*']);
        $this->postJson('/api/communication/calls/'.$id.'/token')->assertConflict();
        $this->assertDatabaseHas('communication_calls', ['id' => $id, 'dispatcher_key' => null, 'accepted_at' => null]);
        $this->getJson('/api/communication/calls')->assertJsonCount(0, 'data');
    }

    public function test_sos_call_is_private_and_one_dispatcher_cannot_answer_two_calls(): void
    {
        [$resident, $dispatcher] = $this->callers();
        $first = $this->postJson('/api/communication/calls/sos')->assertCreated()->json('data.id');
        $otherResident = ResidentUser::factory()->accepted()->create();
        Sanctum::actingAs($otherResident, ['*']);
        $this->getJson('/api/communication/calls/'.$first)->assertForbidden();
        $this->getJson('/api/communication/calls')->assertJsonCount(0, 'data');
        $second = $this->postJson('/api/communication/calls/sos')->assertCreated()->json('data.id');
        Sanctum::actingAs($dispatcher, ['*']);
        $this->postJson('/api/communication/calls/'.$first.'/token')->assertOk();
        $this->postJson('/api/communication/calls/'.$second.'/token')->assertConflict();
        $this->assertDatabaseHas('communication_calls', ['id' => $second, 'dispatcher_key' => null]);
    }
}
