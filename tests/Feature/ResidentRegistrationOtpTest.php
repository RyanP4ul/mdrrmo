<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentRegistrationOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sms_gateway.url' => 'https://sms.example.test',
            'services.sms_gateway.admin_token' => 'test-admin-secret',
            'services.sms_gateway.device_id' => 'gateway-phone-1',
            'services.sms_gateway.otp_length' => 6,
            'services.sms_gateway.otp_ttl' => 300,
            'services.sms_gateway.resend_cooldown' => 60,
            'services.sms_gateway.verification_token_ttl' => 900,
        ]);
    }

    public function test_full_otp_send_verify_and_register_flow_converts_ph_number_to_e164(): void
    {
        Http::fake([
            'https://sms.example.test/otp/send' => Http::response([
                'success' => true,
                'otp_length' => 6,
                'expires_at' => now()->addMinutes(5)->toIso8601String(),
                'sms_id' => 42,
                'message' => 'OTP sent',
            ], 201),
            'https://sms.example.test/otp/verify' => Http::response([
                'success' => true,
                'message' => 'OTP verified',
                'verified' => true,
            ], 200),
        ]);

        // Step 1: Send OTP with PH local format (09XX...)
        $this->postJson('/api/auth/mobile/register/otp/send', [
            'contact' => '0912 345 6789',
        ])->assertOk()
            ->assertJsonPath('otp_length', 6)
            ->assertJsonPath('resend_after_seconds', 60);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://sms.example.test/otp/send'
                && $request->hasHeader('X-Admin-Token', 'test-admin-secret')
                && $request['phone_number'] === '+639123456789'
                && $request['device_id'] === 'gateway-phone-1'
                && $request['purpose'] === 'resident_registration'
                && $request['length'] === 6
                && $request['ttl_seconds'] === 300
                && str_contains((string) $request['message_template'], '{{code}}');
        });

        // Resend cooldown prevents immediate re-sending
        $this->postJson('/api/auth/mobile/register/otp/send', [
            'contact' => '09123456789',
        ])->assertTooManyRequests()
            ->assertHeader('Retry-After');

        // Step 2: Verify OTP
        $verify = $this->postJson('/api/auth/mobile/register/otp/verify', [
            'contact' => '0912 345 6789',
            'code' => '123456',
        ])->assertOk()
            ->assertJsonPath('verified', true);

        $token = $verify->json('verification_token');
        $this->assertIsString($token);
        $this->assertGreaterThanOrEqual(32, strlen($token));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://sms.example.test/otp/verify'
                && $request->hasHeader('X-Admin-Token', 'test-admin-secret')
                && $request['phone_number'] === '+639123456789'
                && $request['code'] === '123456'
                && $request['purpose'] === 'resident_registration';
        });

        // Step 3: Registration with mismatched phone fails
        $this->postJson('/api/auth/mobile/register', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'contact' => '09171112222',
            'otp_verification_token' => $token,
            'address' => 'Balagtas, Bulacan',
            'email' => 'juan@example.test',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'consent' => true,
            'consent_version' => config('mobile.consent_version'),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('otp_verification_token');

        // Step 4: Registration with matching phone (+63 or 09 format) succeeds
        $this->postJson('/api/auth/mobile/register', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'contact' => '+639123456789',
            'otp_verification_token' => $token,
            'address' => 'Balagtas, Bulacan',
            'email' => 'juan@example.test',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'consent' => true,
            'consent_version' => config('mobile.consent_version'),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated();

        $this->assertDatabaseHas('residents', [
            'email' => 'juan@example.test',
            'contact_number' => '+639123456789',
            'registration_status' => 'pending',
        ]);

        // Step 5: Consumed token cannot be reused for another account
        $this->postJson('/api/auth/mobile/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'contact' => '09123456789',
            'otp_verification_token' => $token,
            'address' => 'Balagtas, Bulacan',
            'email' => 'maria@example.test',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'consent' => true,
            'consent_version' => config('mobile.consent_version'),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('otp_verification_token');
    }

    public function test_auto_selects_active_online_device_when_device_id_is_not_configured(): void
    {
        config(['services.sms_gateway.device_id' => '']);

        Http::fake([
            'https://sms.example.test/devices/health' => Http::response([
                'success' => true,
                'online_count' => 1,
                'offline_count' => 1,
                'devices' => [
                    ['device_id' => 'offline-1', 'is_active' => true, 'health_status' => 'offline'],
                    ['device_id' => 'online-2', 'is_active' => true, 'health_status' => 'online'],
                ],
            ], 200),
            'https://sms.example.test/otp/send' => Http::response([
                'success' => true,
                'otp_length' => 6,
                'expires_at' => now()->addMinutes(5)->toIso8601String(),
                'sms_id' => 99,
            ], 201),
        ]);

        $this->postJson('/api/auth/mobile/register/otp/send', [
            'contact' => '09171234567',
        ])->assertOk();

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://sms.example.test/otp/send'
            && $r['device_id'] === 'online-2');
    }

    public function test_verify_maps_gateway_invalid_expired_and_rate_limit_errors(): void
    {
        Http::fake([
            'https://sms.example.test/otp/verify' => Http::sequence()
                ->push(['error' => 'Invalid code', 'remaining_attempts' => 4], 400)
                ->push(['error' => 'OTP expired'], 410)
                ->push(['error' => 'Maximum attempts exceeded'], 429),
        ]);

        $this->postJson('/api/auth/mobile/register/otp/verify', [
            'contact' => '09171234567',
            'code' => '000000',
        ])->assertUnprocessable()
            ->assertJsonPath('remaining_attempts', 4)
            ->assertJsonValidationErrors('code');

        $this->postJson('/api/auth/mobile/register/otp/verify', [
            'contact' => '09171234567',
            'code' => '000000',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->postJson('/api/auth/mobile/register/otp/verify', [
            'contact' => '09171234567',
            'code' => '000000',
        ])->assertTooManyRequests();
    }

    public function test_verification_token_remains_valid_after_registration_validation_failure(): void
    {
        Http::fake([
            'https://sms.example.test/otp/verify' => Http::response([
                'success' => true,
                'verified' => true,
            ], 200),
        ]);

        $token = $this->postJson('/api/auth/mobile/register/otp/verify', [
            'contact' => '09171234567',
            'code' => '654321',
        ])->assertOk()->json('verification_token');

        // First registration attempt fails on invalid email
        $this->postJson('/api/auth/mobile/register', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'contact' => '09171234567',
            'otp_verification_token' => $token,
            'address' => 'Balagtas, Bulacan',
            'email' => 'not-an-email',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'consent' => true,
            'consent_version' => config('mobile.consent_version'),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        // Second attempt with corrected email succeeds using the same OTP token
        $this->postJson('/api/auth/mobile/register', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'contact' => '09171234567',
            'otp_verification_token' => $token,
            'address' => 'Balagtas, Bulacan',
            'email' => 'ana@example.test',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'consent' => true,
            'consent_version' => config('mobile.consent_version'),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated();
    }
}
