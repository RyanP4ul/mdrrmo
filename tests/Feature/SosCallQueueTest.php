<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\DispatcherUser;
use App\Models\ResidentUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SosCallQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agora.app_id' => str_repeat('a', 32), 'agora.app_certificate' => str_repeat('b', 32), 'agora.sos_queue_seconds' => 600]);
        $this->freezeTime();
    }

    private function asCaller(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    private function requestCall(ResidentUser $resident): string
    {
        return $this->asCaller($resident)->postJson('/api/communication/calls/sos')->assertCreated()->json('data.id');
    }

    public function test_waiting_calls_are_promoted_by_triage_priority_when_the_busy_dispatcher_finishes(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $this->asCaller($dispatcher)->postJson('/api/communication/calls/availability', ['status' => 'Available'])->assertOk();
        $first = $this->requestCall(ResidentUser::factory()->accepted()->create());
        $this->asCaller($dispatcher)->postJson('/api/communication/calls/'.$first.'/token')->assertOk();
        $older = $this->requestCall(ResidentUser::factory()->accepted()->create());
        $criticalResident = ResidentUser::factory()->accepted()->create();
        $critical = $this->requestCall($criticalResident);
        $this->asCaller($dispatcher)->getJson('/api/communication/calls')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('dispatchers.0.status', 'Busy');
        $this->postJson('/api/communication/calls/'.$critical.'/manage', ['action' => 'priority', 'priority' => 0])->assertOk();
        $this->postJson('/api/communication/calls/'.$older.'/token')->assertConflict();
        $this->postJson('/api/communication/calls/'.$first.'/end')->assertOk();
        $this->assertDatabaseHas('communication_calls', ['id' => $critical, 'offered_dispatcher_key' => Account::keyFor($dispatcher), 'queue_state' => 'ringing']);
        $this->assertDatabaseHas('communication_calls', ['id' => $older, 'queue_state' => 'waiting']);
        $this->asCaller($criticalResident)->getJson('/api/communication/calls/'.$critical)->assertOk()->assertJsonPath('data.queue_state', 'ringing');
    }

    public function test_simultaneous_requests_route_to_different_dispatchers_and_cannot_be_stolen(): void
    {
        $one = DispatcherUser::factory()->create();
        $two = DispatcherUser::factory()->create();
        foreach ([$one, $two] as $dispatcher) {
            $this->asCaller($dispatcher)->postJson('/api/communication/calls/availability', ['status' => 'Available'])->assertOk();
        }
        $first = $this->requestCall(ResidentUser::factory()->accepted()->create());
        $second = $this->requestCall(ResidentUser::factory()->accepted()->create());
        $this->assertDatabaseHas('communication_calls', ['id' => $first, 'offered_dispatcher_key' => Account::keyFor($one)]);
        $this->assertDatabaseHas('communication_calls', ['id' => $second, 'offered_dispatcher_key' => Account::keyFor($two)]);
        $this->asCaller($two)->postJson('/api/communication/calls/'.$first.'/token')->assertConflict();
        $this->postJson('/api/communication/calls/'.$second.'/token')->assertOk();
        $this->asCaller($one)->postJson('/api/communication/calls/'.$first.'/token')->assertOk();
    }

    public function test_duplicate_resident_requests_resume_one_call_and_cancellation_and_rejection_notify_the_caller(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $resident = ResidentUser::factory()->accepted()->create();
        $id = $this->requestCall($resident);
        $this->postJson('/api/communication/calls/sos')->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('communication_calls', 1);
        $this->postJson('/api/communication/calls/'.$id.'/manage', ['action' => 'priority', 'priority' => 0])->assertForbidden();
        $this->postJson('/api/communication/calls/'.$id.'/end')->assertOk()->assertJsonPath('data.end_reason', 'cancelled');
        $id = $this->requestCall($resident);
        $this->asCaller($dispatcher)->postJson('/api/communication/calls/'.$id.'/manage', ['action' => 'reject'])->assertOk();
        $this->asCaller($resident)->getJson('/api/communication/calls/'.$id)->assertOk()->assertJsonPath('data.end_reason', 'rejected')->assertJsonPath('data.joinable', false);
    }

    public function test_queue_survives_a_temporary_dispatcher_disconnect_and_routes_again_on_reconnect(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $this->asCaller($dispatcher)->postJson('/api/communication/calls/availability', ['status' => 'Available'])->assertOk();
        $resident = ResidentUser::factory()->accepted()->create();
        $id = $this->requestCall($resident);
        $this->travel(21)->seconds();
        $this->asCaller($resident)->getJson('/api/communication/calls/'.$id)->assertOk()->assertJsonPath('data.queue_state', 'waiting')->assertJsonPath('data.queue_position', 1);
        $this->assertDatabaseHas('communication_calls', ['id' => $id, 'ended_at' => null]);
        $this->asCaller($dispatcher)->getJson('/api/communication/calls')->assertOk()->assertJsonPath('dispatchers.0.status', 'Available');
        $this->assertDatabaseHas('communication_calls', ['id' => $id, 'offered_dispatcher_key' => Account::keyFor($dispatcher)]);
        $this->postJson('/api/communication/calls/availability', ['status' => 'Offline'])->assertOk()->assertJsonPath('data.0.status', 'Offline');
        $this->assertDatabaseHas('communication_calls', ['id' => $id, 'queue_state' => 'waiting']);
    }

    public function test_abandoned_and_expired_calls_release_capacity_and_report_a_terminal_reason(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $first = $this->requestCall(ResidentUser::factory()->accepted()->create());
        $this->asCaller($dispatcher)->postJson('/api/communication/calls/'.$first.'/token')->assertOk();
        $waitingResident = ResidentUser::factory()->accepted()->create();
        $waiting = $this->requestCall($waitingResident);
        $this->travel(46)->seconds();
        $this->asCaller($waitingResident)->getJson('/api/communication/calls/'.$waiting)->assertOk()->assertJsonPath('data.joinable', true);
        $this->assertDatabaseHas('communication_calls', ['id' => $first, 'end_reason' => 'disconnected']);
        $this->asCaller($dispatcher)->getJson('/api/communication/calls')->assertOk();
        $this->assertDatabaseHas('communication_calls', ['id' => $waiting, 'offered_dispatcher_key' => Account::keyFor($dispatcher)]);
        $this->travel(601)->seconds();
        $this->asCaller($waitingResident)->getJson('/api/communication/calls/'.$waiting)->assertOk()->assertJsonPath('data.joinable', false)->assertJsonPath('data.end_reason', 'timeout');
    }

    public function test_an_unanswered_offer_releases_the_dispatcher_for_the_next_queued_caller(): void
    {
        config(['agora.sos_ring_seconds' => 10]);
        $dispatcher = DispatcherUser::factory()->create();
        $this->asCaller($dispatcher)->postJson('/api/communication/calls/availability', ['status' => 'Available'])->assertOk();
        $firstResident = ResidentUser::factory()->accepted()->create();
        $first = $this->requestCall($firstResident);
        $second = $this->requestCall(ResidentUser::factory()->accepted()->create());
        $this->travel(11)->seconds();
        $this->asCaller($firstResident)->getJson('/api/communication/calls/'.$first)->assertOk()->assertJsonPath('data.queue_state', 'waiting');
        $this->assertDatabaseHas('communication_calls', ['id' => $second, 'offered_dispatcher_key' => Account::keyFor($dispatcher)]);
        $this->asCaller($dispatcher)->postJson('/api/communication/calls/'.$second.'/manage', ['action' => 'priority', 'priority' => -1])->assertUnprocessable();
        Sanctum::actingAs($dispatcher, ['session', 'staff-read']);
        $this->postJson('/api/communication/calls/availability', ['status' => 'Available'])->assertForbidden();
    }

    public function test_guests_wait_in_the_same_queue_and_receive_an_offer_when_the_active_call_ends(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $first = $this->requestCall(ResidentUser::factory()->accepted()->create());
        $this->asCaller($dispatcher)->postJson('/api/communication/calls/'.$first.'/token')->assertOk();
        $guest = $this->postJson('/api/guest/calls')->assertCreated()->assertJsonPath('data.queue_state', 'waiting')->assertJsonPath('data.queue_position', 1);
        $this->postJson('/api/communication/calls/'.$first.'/end')->assertOk();
        $this->getJson('/api/guest/calls/'.$guest->json('data.id'), ['X-Guest-Call-Token' => $guest->json('guest_token')])
            ->assertOk()->assertJsonPath('data.queue_state', 'ringing')->assertJsonPath('data.offered_dispatcher_key', Account::keyFor($dispatcher));
    }
}
