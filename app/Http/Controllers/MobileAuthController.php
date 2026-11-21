<?php

namespace App\Http\Controllers;

use App\Events\DriverLocationUpdated;
use App\Models\Account;
use App\Models\DriverLocation;
use App\Models\ResidentUser;
use App\Models\User;
use App\Services\SmsGatewayException;
use App\Services\SmsGatewayService;
use App\Support\MobileValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class MobileAuthController extends Controller
{
    private const RESIDENT_ID_TYPES = [
        'National ID',
        'Driver License',
        'PhilHealth ID',
        'Voters ID',
        'Passport',
    ];

    public function sendRegistrationOtp(Request $request, SmsGatewayService $smsGateway): JsonResponse
    {
        MobileValidation::normalizePhone($request, 'contact');
        $data = $request->validate([
            'contact' => MobileValidation::phoneRules(),
        ]);

        $remaining = $smsGateway->resendCooldownRemaining($data['contact']);
        if ($remaining > 0) {
            return response()->json([
                'message' => "Please wait {$remaining} seconds before requesting another verification code.",
                'retry_after' => $remaining,
            ], 429)->header('Retry-After', (string) $remaining);
        }

        try {
            $result = $smsGateway->sendOtp($data['contact']);
        } catch (SmsGatewayException $e) {
            return $this->smsGatewayErrorResponse($e);
        }

        return response()->json([
            'message' => 'Verification code sent to your mobile number.',
            'otp_length' => $result['otp_length'],
            'expires_at' => $result['expires_at'],
            'resend_after_seconds' => $result['resend_after_seconds'],
        ]);
    }

    public function verifyRegistrationOtp(Request $request, SmsGatewayService $smsGateway): JsonResponse
    {
        MobileValidation::normalizePhone($request, 'contact');
        $data = $request->validate([
            'contact' => MobileValidation::phoneRules(),
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ], [
            'code.regex' => 'Enter a valid numeric verification code.',
        ]);

        try {
            $smsGateway->verifyOtp($data['contact'], $data['code']);
        } catch (SmsGatewayException $e) {
            return $this->smsGatewayErrorResponse($e);
        }

        $issued = $smsGateway->issueVerificationToken($data['contact']);

        return response()->json([
            'verified' => true,
            'verification_token' => $issued['token'],
            'expires_in' => $issued['expires_in'],
            'message' => 'Phone number verified.',
        ]);
    }

    public function register(Request $request, SmsGatewayService $smsGateway): JsonResponse
    {
        MobileValidation::normalizePhone($request, 'contact');
        $data = $request->validate([
            'name' => ['required_without_all:first_name,last_name', 'nullable', 'string', 'max:120'],
            'first_name' => ['required_without:name', 'nullable', 'string', 'max:60'],
            'last_name' => ['required_without:name', 'nullable', 'string', 'max:60'],
            'contact' => MobileValidation::phoneRules(),
            'otp_verification_token' => ['required', 'string', 'min:32', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'consent' => ['required', 'accepted'],
            'consent_version' => ['required', Rule::in([config('mobile.consent_version')])],
            'id_type' => ['nullable', 'string', Rule::in(self::RESIDENT_ID_TYPES)],
            'id_photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ], [
            'otp_verification_token.required' => 'Please verify your contact number with the SMS code before submitting.',
        ]);

        if (! $smsGateway->matchesVerifiedPhone($data['otp_verification_token'], $data['contact'])) {
            throw ValidationException::withMessages([
                'otp_verification_token' => 'Your phone verification has expired or does not match this contact number. Please verify again.',
            ]);
        }

        if (! empty($data['first_name']) && ! empty($data['last_name'])) {
            $firstName = preg_replace('/\s+/', ' ', trim((string) $data['first_name']));
            $lastName = preg_replace('/\s+/', ' ', trim((string) $data['last_name']));
            if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 60 || mb_strlen($lastName) > 60) {
                throw ValidationException::withMessages(['name' => 'Enter your first and last name, each at most 60 characters.']);
            }
            $nameParts = [$firstName, $lastName];
        } else {
            $nameParts = MobileValidation::nameParts((string) ($data['name'] ?? ''));
        }

        if (mb_strtolower($nameParts[0]) === mb_strtolower($nameParts[1])) {
            throw ValidationException::withMessages([
                'last_name' => 'First Name and Last Name must be different.',
                'name' => 'First Name and Last Name must be different.',
            ]);
        }

        $nameExists = ResidentUser::query()
            ->whereRaw('LOWER(first_name) = ? AND LOWER(last_name) = ?', [
                mb_strtolower($nameParts[0]),
                mb_strtolower($nameParts[1]),
            ])
            ->exists();
        if ($nameExists) {
            throw ValidationException::withMessages([
                'first_name' => 'A resident with this First Name and Last Name is already registered.',
                'name' => 'A resident with this First Name and Last Name is already registered.',
            ]);
        }

        $emailExists = collect(Account::classes())->contains(
            fn (string $model): bool => $model::query()->where('email', $data['email'])->exists(),
        );
        if ($emailExists) {
            throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
        }

        $idDocumentPath = $request->hasFile('id_photo')
            ? $request->file('id_photo')->store('resident-ids', 'r2')
            : null;

        try {
            DB::transaction(function () use ($nameParts, $data, $idDocumentPath): void {
                $resident = ResidentUser::query()->create([
                    'first_name' => $nameParts[0],
                    'last_name' => $nameParts[1],
                    'email' => $data['email'],
                    'contact_number' => $data['contact'],
                    'street' => $data['address'],
                    'password' => $data['password'],
                    'id_type' => $data['id_type'] ?? null,
                    'id_document_path' => $idDocumentPath,
                    'status' => 'active',
                    'registration_status' => 'pending',
                ]);

                DB::table('resident_consents')->insert([
                    'resident_id' => $resident->getKey(), 'version' => $data['consent_version'],
                    'statement' => config('mobile.consent_text'), 'consented_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            if ($idDocumentPath) {
                Storage::disk('r2')->delete($idDocumentPath);
            }
            throw $e;
        }

        $smsGateway->consumeVerificationToken($data['otp_verification_token']);

        return response()->json([
            'message' => 'Registration submitted. Your account is waiting for review.',
        ], 201);
    }

    private function smsGatewayErrorResponse(SmsGatewayException $e): JsonResponse
    {
        $payload = ['message' => $e->getMessage()] + $e->extra;
        if ($e->statusCode === 422 && $e->field !== null) {
            $payload['errors'] = [$e->field => [$e->getMessage()]];
        }

        return response()->json($payload, $e->statusCode);
    }

    public function authenticate(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::findByEmail($request->email);

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (
            $user->status !== 'active'
            || ! $user->hasRole('resident', 'driver/responder', 'admin', 'dispatcher')
        ) {
            return response()->json([
                'message' => 'This account cannot access the mobile app.',
            ], 403);
        }

        $token = $user->createToken(
            $request->device_name,
            match ($user->role) {
                'resident' => ['session', 'resident-read', 'resident-write'],
                'driver/responder' => ['session', 'driver-read', 'driver-write'],
                'dispatcher' => ['session', 'staff-read', 'staff-write'],
                'admin' => ['session', 'staff-read', 'staff-write', 'admin-manage'],
            },
            now()->addMinutes(config('mobile.token_minutes')),
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function policy(): JsonResponse
    {
        return response()->json([
            'version' => config('mobile.consent_version'), 'consent' => config('mobile.consent_text'),
            'privacy' => config('mobile.privacy_text'), 'timezone' => config('mobile.timezone'),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            DB::table('vehicle_tracking_sessions')->where('token_id', $token->id)->whereNull('stopped_at')->update(['stopped_at' => now()]);
            $sessions = DB::table('driver_location_sessions')->where('token_id', $token->id)->pluck('id');
            DB::table('driver_location_sessions')->whereIn('id', $sessions)->whereNull('stopped_at')->update(['stopped_at' => now()]);
            $locations = DriverLocation::whereIn('session_id', $sessions)->get();
            $token->delete();
            foreach ($locations as $location) {
                $location->update(['expires_at' => now()->subSecond()]);
                try {
                    DriverLocationUpdated::dispatch($location);
                } catch (\Throwable $error) {
                    report($error);
                }
            }
        }

        return response()->json(['message' => 'Logged out.']);
    }
}
