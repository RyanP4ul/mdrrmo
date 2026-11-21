<?php

namespace App\Services;

use App\Support\MobileValidation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Integrates with the production SMS Gateway API (/otp/send, /otp/verify, /devices/health).
 */
class SmsGatewayService
{
    public const REGISTRATION_PURPOSE = 'resident_registration';

    private const DEVICE_CACHE_KEY = 'sms_gateway:active_device_id';

    private const VERIFIED_TOKEN_PREFIX = 'sms_gateway:reg_otp_verified:';

    private const COOLDOWN_PREFIX = 'sms_gateway:reg_otp_cooldown:';

    /**
     * Request an OTP from the production SMS Gateway.
     *
     * @return array{otp_length: int, expires_at: ?string, sms_id: mixed, resend_after_seconds: int}
     */
    public function sendOtp(string $phone, string $purpose = self::REGISTRATION_PURPOSE): array
    {
        $e164 = MobileValidation::toE164($phone);
        $deviceId = $this->resolveDeviceId();
        $length = max(4, min(8, (int) config('services.sms_gateway.otp_length', 6)));
        $ttl = max(60, min(3600, (int) config('services.sms_gateway.otp_ttl', 300)));
        $template = (string) config(
            'services.sms_gateway.message_template',
            'Your MDRRMO verification code is {{code}}. Valid for 5 minutes.'
        );

        try {
            $response = $this->client()->post('/otp/send', [
                'phone_number' => $e164,
                'device_id' => $deviceId,
                'purpose' => $purpose,
                'length' => $length,
                'ttl_seconds' => $ttl,
                'message_template' => $template,
            ]);
        } catch (ConnectionException $e) {
            Log::error('SMS gateway connection failed while sending OTP.', ['error' => $e->getMessage()]);

            throw new SmsGatewayException(
                'SMS verification service is temporarily unavailable. Please try again shortly.',
                503,
                null,
                [],
                $e
            );
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful()) {
            return [
                'otp_length' => (int) ($body['otp_length'] ?? $length),
                'expires_at' => isset($body['expires_at']) ? (string) $body['expires_at'] : null,
                'sms_id' => $body['sms_id'] ?? null,
                'resend_after_seconds' => $this->startResendCooldown($e164),
            ];
        }

        if ($response->status() === 429) {
            throw new SmsGatewayException(
                'Too many verification code requests. Please wait before trying again.',
                429,
                'contact'
            );
        }

        if ($response->status() === 400) {
            throw new SmsGatewayException(
                (string) ($body['error'] ?? 'Enter a valid mobile number.'),
                422,
                'contact'
            );
        }

        if ($response->status() === 404 && blank(config('services.sms_gateway.device_id'))) {
            Cache::forget(self::DEVICE_CACHE_KEY);
        }

        Log::error('SMS gateway rejected OTP send request.', [
            'status' => $response->status(),
            'error' => $body['error'] ?? null,
            'device_id' => $deviceId,
        ]);

        throw new SmsGatewayException(
            'SMS verification service is temporarily unavailable. Please try again shortly.',
            503
        );
    }

    /**
     * Verify an OTP against the production SMS Gateway.
     */
    public function verifyOtp(string $phone, string $code, string $purpose = self::REGISTRATION_PURPOSE): bool
    {
        $e164 = MobileValidation::toE164($phone);

        try {
            $response = $this->client()->post('/otp/verify', [
                'phone_number' => $e164,
                'code' => trim($code),
                'purpose' => $purpose,
            ]);
        } catch (ConnectionException $e) {
            Log::error('SMS gateway connection failed while verifying OTP.', ['error' => $e->getMessage()]);

            throw new SmsGatewayException(
                'SMS verification service is temporarily unavailable. Please try again shortly.',
                503,
                null,
                [],
                $e
            );
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && ($body['verified'] ?? false) === true) {
            return true;
        }

        if ($response->status() === 400) {
            $remaining = isset($body['remaining_attempts']) ? (int) $body['remaining_attempts'] : null;
            $message = $remaining !== null
                ? sprintf(
                    'Invalid verification code. %d %s remaining.',
                    $remaining,
                    $remaining === 1 ? 'attempt' : 'attempts'
                )
                : (string) ($body['error'] ?? 'Invalid verification code.');

            throw new SmsGatewayException(
                $message,
                422,
                'code',
                $remaining !== null ? ['remaining_attempts' => $remaining] : []
            );
        }

        if ($response->status() === 404) {
            throw new SmsGatewayException(
                'Verification code not found or already used. Please request a new code.',
                422,
                'code'
            );
        }

        if ($response->status() === 410) {
            throw new SmsGatewayException(
                'Verification code has expired. Please request a new code.',
                422,
                'code'
            );
        }

        if ($response->status() === 429) {
            throw new SmsGatewayException(
                'Maximum verification attempts exceeded. Please request a new code.',
                429,
                'code'
            );
        }

        Log::error('SMS gateway rejected OTP verify request.', [
            'status' => $response->status(),
            'error' => $body['error'] ?? null,
        ]);

        throw new SmsGatewayException(
            'SMS verification service is temporarily unavailable. Please try again shortly.',
            503
        );
    }

    /**
     * Issue a short-lived server-side verification token bound to this phone number.
     *
     * @return array{token: string, expires_in: int}
     */
    public function issueVerificationToken(string $phone): array
    {
        $ttl = max(60, (int) config('services.sms_gateway.verification_token_ttl', 900));
        $token = Str::random(64);
        $e164 = MobileValidation::toE164($phone);

        Cache::put($this->verifiedTokenKey($token), ['phone' => $e164], now()->addSeconds($ttl));

        return [
            'token' => $token,
            'expires_in' => $ttl,
        ];
    }

    public function matchesVerifiedPhone(?string $token, string $phone): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        $record = Cache::get($this->verifiedTokenKey($token));
        if (! is_array($record) || ! isset($record['phone']) || ! is_string($record['phone'])) {
            return false;
        }

        return hash_equals($record['phone'], MobileValidation::toE164($phone));
    }

    public function consumeVerificationToken(string $token): void
    {
        if ($token !== '') {
            Cache::forget($this->verifiedTokenKey($token));
        }
    }

    public function resendCooldownRemaining(string $phone): int
    {
        $expiresAt = Cache::get($this->cooldownKey($phone));
        if (! is_int($expiresAt)) {
            return 0;
        }

        return max(0, $expiresAt - now()->timestamp);
    }

    public function startResendCooldown(string $phone): int
    {
        $cooldown = max(0, (int) config('services.sms_gateway.resend_cooldown', 60));
        if ($cooldown > 0) {
            $expiresAt = now()->timestamp + $cooldown;
            Cache::put($this->cooldownKey($phone), $expiresAt, now()->addSeconds($cooldown));
        }

        return $cooldown;
    }

    private function resolveDeviceId(): string
    {
        $configured = trim((string) config('services.sms_gateway.device_id', ''));
        if ($configured !== '') {
            return $configured;
        }

        $cached = Cache::get(self::DEVICE_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->client()->get('/devices/health');
        } catch (ConnectionException $e) {
            Log::error('SMS gateway connection failed while fetching devices.', ['error' => $e->getMessage()]);

            throw new SmsGatewayException(
                'SMS verification service is temporarily unavailable. Please try again shortly.',
                503,
                null,
                [],
                $e
            );
        }

        $devices = $response->successful() ? $response->json('devices') : null;
        if (! is_array($devices)) {
            Log::error('SMS gateway failed to return devices health list.', ['status' => $response->status()]);

            throw new SmsGatewayException(
                'SMS verification service is temporarily unavailable. Please try again shortly.',
                503
            );
        }

        $online = collect($devices)->first(
            fn ($d) => is_array($d)
                && ($d['is_active'] ?? false) === true
                && ($d['health_status'] ?? null) === 'online'
                && ! empty($d['device_id'])
        );

        $selected = $online ?? collect($devices)->first(
            fn ($d) => is_array($d)
                && ($d['is_active'] ?? false) === true
                && ! empty($d['device_id'])
        );

        if (! is_array($selected) || empty($selected['device_id'])) {
            Log::error('No active SMS gateway device is available to send OTP.');

            throw new SmsGatewayException(
                'SMS verification service is temporarily unavailable. Please try again shortly.',
                503
            );
        }

        $deviceId = (string) $selected['device_id'];
        Cache::put(self::DEVICE_CACHE_KEY, $deviceId, now()->addSeconds(60));

        return $deviceId;
    }

    private function client(): PendingRequest
    {
        $baseUrl = rtrim((string) config('services.sms_gateway.url', ''), '/');
        $adminToken = (string) config('services.sms_gateway.admin_token', '');

        if ($baseUrl === '' || $adminToken === '') {
            Log::error('SMS gateway is not configured (missing SMS_GATEWAY_URL or SMS_GATEWAY_ADMIN_TOKEN).');

            throw new SmsGatewayException(
                'SMS verification service is not configured. Please contact MDRRMO support.',
                503
            );
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Admin-Token' => $adminToken])
            ->timeout(max(5, (int) config('services.sms_gateway.timeout', 15)));
    }

    private function verifiedTokenKey(string $token): string
    {
        return self::VERIFIED_TOKEN_PREFIX.hash('sha256', $token);
    }

    private function cooldownKey(string $phone): string
    {
        return self::COOLDOWN_PREFIX.hash('sha256', MobileValidation::toE164($phone));
    }
}
