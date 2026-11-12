<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CommunicationCall;
use App\Models\DispatcherUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestSosCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agora.app_id' => str_repeat('a', 32), 'agora.app_certificate' => str_repeat('b', 32)]);
    }

    public function test_anonymous_call_needs_no_personal_information_and_only_its_capability_can_access_it(): void
    {
        $dispatcher = DispatcherUser::factory()->create();
        $response = $this->postJson('/api/guest/calls')->assertCreated()->assertJsonPath('data.resident_name', 'Guest')
            ->assertJsonPath('data.caller_type', 'guest')->assertJsonCount(2, 'data.participants');
        $id = $response->json('data.id');
        $secret = $response->json('guest_token');
        $this->assertSame(hash('sha256', $secret), CommunicationCall::find($id)->guest_token_hash);
        $this->assertDatabaseCount('residents', 0);
        foreach (['GET' => '', 'POST' => '/token'] as $method => $suffix) {
            $this->json($method, '/api/guest/calls/'.$id.$suffix)->assertForbidden();
            $this->json($method, '/api/guest/calls/'.$id.$suffix, [], ['X-Guest-Call-Token' => str_repeat('x', 64)])->assertForbidden();
        }
        $this->withHeaders(['X-Guest-Call-Token' => $secret])->postJson('/api/guest/calls/'.$id.'/token')
            ->assertOk()->assertJsonPath('data.uid', 1)->assertJsonPath('data.channel', 'mdrrmo-'.$id);
        $this->actingAs($dispatcher)->getJson('/communication/calls')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.guest_token_hash')->assertJsonMissingPath('data.0.guest_token');
        $this->postJson('/communication/calls/'.$id.'/token')->assertOk()->assertJsonPath('data.uid', 2);
        $this->postJson('/api/guest/calls/'.$id.'/end')->assertOk()->assertJsonPath('data.status', 'ended');
        $this->postJson('/api/guest/calls/'.$id.'/token')->assertConflict();
    }

    public function test_guest_retry_is_idempotent_and_optional_details_cannot_override_priority_or_dispatcher(): void
    {
        DispatcherUser::factory()->create();
        $headers = ['X-Guest-Call-Token' => str_repeat('c', 64)];
        $id = $this->postJson('/api/guest/calls', ['name' => 'Ana', 'contact' => '09123456789', 'address' => 'Balagtas', 'priority' => 0, 'dispatcher_key' => 'dispatcher-999'], $headers)
            ->assertCreated()->assertJsonPath('data.resident_name', 'Ana')->assertJsonPath('data.resident_contact', '09123456789')
            ->assertJsonPath('data.resident_address', 'Balagtas')->assertJsonPath('data.priority', 2)->json('data.id');
        $this->postJson('/api/guest/calls', [], $headers)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('communication_calls', 1);
        $this->assertNull(CommunicationCall::find($id)->dispatcher_key);
        $this->postJson('/api/guest/calls', ['name' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_public_contact_is_an_active_dispatcher_number_and_unavailable_calling_fails_cleanly(): void
    {
        DispatcherUser::factory()->create(['status' => 'inactive', 'phone_number' => '09000000000']);
        $this->postJson('/api/guest/calls')->assertServiceUnavailable();
        DispatcherUser::factory()->create(['contact_number' => '09999999999', 'phone_number' => '09123456789']);
        $this->getJson('/api/connectivity?include_contact=1')->assertOk()->assertJsonPath('dispatcher_phone', '09123456789');
        config(['agora.app_certificate' => '']);
        $this->postJson('/api/guest/calls')->assertServiceUnavailable();
        $this->assertDatabaseCount('communication_calls', 0);
    }

    public function test_administrator_can_configure_a_dispatcher_sos_number_and_other_roles_cannot_change_it(): void
    {
        $admin = AdminUser::factory()->create();
        $payload = ['first_name' => 'Dispatch', 'last_name' => 'Office', 'email' => 'dispatch-phone@example.test',
            'contact_number' => '09999999999', 'phone_number' => '+639123456789', 'role' => 'dispatcher', 'status' => 'active'];
        $this->actingAs($admin)->post(route('admin.users.store'), $payload)->assertRedirect();
        $dispatcher = DispatcherUser::where('email', $payload['email'])->firstOrFail();
        $this->assertSame('+639123456789', $dispatcher->phone_number);
        $this->get(route('admin.users.index', ['role' => 'dispatcher']))->assertInertia(fn ($page) => $page
            ->where('employees.data.0.phone_number', '+639123456789'));
        $this->putJson(route('admin.users.update', $dispatcher->account_key), [...$payload, 'phone_number' => 'tel:javascript'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone_number');
        $this->assertSame('+639123456789', $dispatcher->refresh()->phone_number);
        $this->actingAs($dispatcher)->putJson(route('admin.users.update', $dispatcher->account_key), [...$payload, 'phone_number' => '+639111111111'])->assertForbidden();
        $this->getJson('/api/connectivity?include_contact=1')->assertOk()->assertJsonPath('dispatcher_phone', '+639123456789');
    }

    public function test_mobile_administrator_can_save_the_dispatcher_phone_without_exposing_edit_access_to_dispatchers(): void
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin, ['*']);
        $payload = ['first_name' => 'Dispatch', 'last_name' => 'Mobile', 'email' => 'mobile-dispatch-phone@example.test',
            'contact_number' => '09999999999', 'phone_number' => '+639123456789', 'password' => 'ValidPass123', 'role' => 'dispatcher', 'status' => 'active'];
        $this->postJson('/api/operations/accounts', $payload)->assertCreated()->assertJsonPath('phone_number', '+639123456789');
        $dispatcher = DispatcherUser::where('email', $payload['email'])->firstOrFail();
        $this->assertSame('+639123456789', $dispatcher->phone_number);
        $this->getJson('/api/operations/accounts?role=dispatcher')->assertOk()->assertJsonPath('data.0.phone_number', '+639123456789');
        Sanctum::actingAs($dispatcher, ['*']);
        unset($payload['password']);
        $this->putJson('/api/operations/accounts/'.$dispatcher->account_key, [...$payload, 'phone_number' => '+639111111111'])->assertForbidden();
        $this->assertSame('+639123456789', $dispatcher->refresh()->phone_number);
    }
}
