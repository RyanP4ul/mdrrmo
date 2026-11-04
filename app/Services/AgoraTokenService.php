<?php

namespace App\Services;

/** RTC-only AccessToken2 encoder. Format: AgoraIO/Tools, AgoraDynamicKey/php/src. */
class AgoraTokenService
{
    public function assertConfigured(): void
    {
        foreach (['app_id', 'app_certificate'] as $key) {
            abort_unless(preg_match('/\A[0-9a-fA-F]{32}\z/', (string) config('agora.'.$key)) === 1,
                503, 'Agora calling is not configured.');
        }
    }

    public function build(string $channel, int $uid, int $seconds): string
    {
        $this->assertConfigured();
        $issued = now()->timestamp;
        $salt = random_int(1, 99999999);
        // RTC service type 1; privileges: join, publish audio, video, and data.
        $rtc = pack('vv', 1, 4);
        foreach ([1, 2, 3, 4] as $privilege) {
            $rtc .= pack('vV', $privilege, $seconds);
        }
        $rtc .= $this->string($channel).$this->string((string) $uid);
        $body = $this->string(config('agora.app_id')).pack('VVVv', $issued, $seconds, $salt, 1).$rtc;
        // AccessToken2 derives the signing key using timestamp and salt as HMAC keys.
        $key = hash_hmac('sha256', config('agora.app_certificate'), pack('V', $issued), true);
        $key = hash_hmac('sha256', $key, pack('V', $salt), true);
        $signature = hash_hmac('sha256', $body, $key, true);

        return '007'.base64_encode(gzcompress($this->string($signature).$body));
    }

    private function string(string $value): string
    {
        return pack('v', strlen($value)).$value;
    }
}
