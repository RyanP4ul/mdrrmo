<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MobileIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        validator(['submission_key' => $key], ['submission_key' => ['required', 'uuid']])->validate();
        $user = $request->user();
        $identity = $user ? $user->getMorphClass().':'.$user->getKey() : 'registration:'.strtolower((string) $request->input('email'));
        $scope = hash('sha256', $identity);
        $payload = $request->all();
        ksort($payload);
        $fingerprint = hash_hmac('sha256', $request->method().' '.$request->path().' '.json_encode($payload, JSON_THROW_ON_ERROR), config('app.key'));

        return DB::transaction(function () use ($request, $next, $scope, $key, $fingerprint): Response {
            DB::table('mobile_submissions')->insertOrIgnore([
                'scope' => $scope, 'submission_key' => $key, 'request_hash' => $fingerprint,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $record = DB::table('mobile_submissions')->where('scope', $scope)->where('submission_key', $key)->lockForUpdate()->first();
            abort_unless(hash_equals($record->request_hash, $fingerprint), 409, 'This submission key belongs to different details. Confirm the earlier submission before starting another.');
            if ($record->response_status !== null) {
                return response(Crypt::decryptString($record->encrypted_response), $record->response_status)
                    ->header('Content-Type', 'application/json')->header('Idempotency-Replayed', 'true');
            }
            $response = $next($request);
            if ($response->isSuccessful()) {
                DB::table('mobile_submissions')->where('id', $record->id)->update([
                    'response_status' => $response->getStatusCode(),
                    'encrypted_response' => Crypt::encryptString($response->getContent()), 'updated_at' => now(),
                ]);
            } else {
                DB::table('mobile_submissions')->where('id', $record->id)->delete();
            }

            return $response;
        }, 3);
    }
}
