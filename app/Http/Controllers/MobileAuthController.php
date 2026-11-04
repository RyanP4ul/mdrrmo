<?php

namespace App\Http\Controllers;

use App\Events\DriverLocationUpdated;
use App\Models\Account;
use App\Models\DriverLocation;
use App\Models\ResidentUser;
use App\Models\User;
use App\Support\MobileValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class MobileAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        MobileValidation::normalizePhone($request, 'contact');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact' => MobileValidation::phoneRules(),
            'address' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'consent' => ['required', 'accepted'],
            'consent_version' => ['required', Rule::in([config('mobile.consent_version')])],
        ]);

        $nameParts = MobileValidation::nameParts($data['name']);

        $emailExists = collect(Account::classes())->contains(
            fn (string $model): bool => $model::query()->where('email', $data['email'])->exists(),
        );
        if ($emailExists) {
            throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
        }

        $resident = ResidentUser::query()->create([
            'first_name' => $nameParts[0],
            'last_name' => $nameParts[1],
            'email' => $data['email'],
            'contact_number' => $data['contact'],
            'street' => $data['address'],
            'password' => $data['password'],
            'status' => 'active',
            'registration_status' => 'pending',
        ]);

        DB::table('resident_consents')->insert([
            'resident_id' => $resident->getKey(), 'version' => $data['consent_version'],
            'statement' => config('mobile.consent_text'), 'consented_at' => now(),
        ]);

        return response()->json([
            'message' => 'Registration submitted. Your account is waiting for review.',
        ], 201);
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
