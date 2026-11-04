<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\MobilePasswordReset;
use App\Support\MobileValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MobileAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'name' => $user->full_name, 'contact' => $user->contact_number, 'email' => $user->email,
            'house_no' => $user->house_no, 'street' => $user->street, 'barangay' => $user->barangay,
            'city' => $user->city, 'province' => $user->province,
            'has_avatar' => filled($user->avatar_path), 'resident' => $user->hasRole('resident'),
            'deletion_request' => DB::table('account_deletion_requests')->where('account_key', $user->account_key)->first(),
            'retention_notice' => 'Deletion requests require office review. Emergency, dispatch and patient-care records are retained according to applicable obligations. Submitting a request does not erase records or close your account. Contact MDRRMO for the retention decision.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('resident'), 403);
        MobileValidation::normalizePhone($request, 'contact');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'contact' => MobileValidation::phoneRules(),
            'house_no' => ['nullable', 'string', 'max:60'], 'street' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'], 'city' => ['required', 'string', 'max:120'],
            'province' => ['required', 'string', 'max:120'],
        ]);
        $names = MobileValidation::nameParts($data['name']);
        unset($data['name'], $data['contact']);
        $request->user()->update($data + ['first_name' => $names[0], 'last_name' => $names[1] ?? '', 'contact_number' => $request->input('contact')]);

        return $this->show($request);
    }

    public function avatar(Request $request): StreamedResponse
    {
        $path = $request->user()->avatar_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096']]);
        $path = $request->file('avatar')->store('mobile-avatars', 'local');
        try {
            DB::transaction(function () use ($request, $path): void {
                $user = $request->user()->newQuery()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
                $old = $user->avatar_path;
                $user->update(['avatar_path' => $path]);
                DB::afterCommit(function () use ($old): void {
                    if ($old && str_starts_with($old, 'mobile-avatars/')) {
                        Storage::disk('local')->delete($old);
                    }
                });
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        $request->user()->refresh();

        return response()->json(['message' => 'Avatar saved.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed', 'different:current_password']]);
        DB::transaction(function () use ($request, $data): void {
            $user = $request->user()->newQuery()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $user->update(['password' => $data['password'], 'remember_token' => Str::random(60)]);
            $current = $request->user()->currentAccessToken();
            $user->tokens()->when($current instanceof PersonalAccessToken, fn ($query) => $query->where('id', '!=', $current->id))->delete();
        });

        return response()->json(['message' => 'Password changed. Other mobile sessions have been signed out.']);
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $user = User::findByEmail($data['email']);
        if ($user?->hasRole('resident', 'driver/responder', 'admin', 'dispatcher') && $user->status === 'active') {
            try {
                Password::broker('mobile')->sendResetLink($data, function ($user, $token): void {
                    $user->notify(new MobilePasswordReset($token));
                });
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return response()->json(['message' => 'If an eligible account exists, a reset code has been sent.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed']]);
        $user = User::findByEmail($data['email']);
        if (! $user?->hasRole('resident', 'driver/responder', 'admin', 'dispatcher') || $user->status !== 'active') {
            throw ValidationException::withMessages(['token' => 'The reset code is invalid or expired.']);
        }
        $status = DB::transaction(function () use ($data, $user) {
            $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            return Password::broker('mobile')->reset($data, function ($user, $password): void {
                $user = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $user->update(['password' => $password, 'remember_token' => Str::random(60)]);
                $user->tokens()->delete();
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'The reset code is invalid or expired.']);
        }

        return response()->json(['message' => 'Password reset. Sign in with your new password.']);
    }

    public function deletion(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:1000'], 'cancel' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($request, $data): void {
            $user = $request->user()->newQuery()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $existing = DB::table('account_deletion_requests')->where('account_key', $user->account_key)->first();
            $cancel = $data['cancel'] ?? false;
            abort_if($cancel && (! $existing || $existing->status !== 'pending'), 422, 'There is no pending request to cancel.');
            if (! $cancel && $existing?->status === 'pending') {
                return;
            }
            DB::table('account_deletion_requests')->updateOrInsert(['account_key' => $user->account_key], [
                'status' => $cancel ? 'cancelled' : 'pending', 'reason' => $data['reason'] ?? null,
                'requested_at' => $cancel ? $existing->requested_at : now(), 'cancelled_at' => $cancel ? now() : null,
                'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
            ]);
        });

        return $this->show($request);
    }
}
