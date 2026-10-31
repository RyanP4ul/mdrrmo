<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Account;
use App\Models\ResidentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
      $data = $request->validate([
          'name' => ['required', 'string', 'max:120'],
          'contact' => ['required', 'string', 'max:20'],
          'address' => ['required', 'string', 'max:255'],
          'email' => ['required', 'email', 'max:255'],
          'password' => ['required', 'string', 'min:8', 'confirmed'],
      ]);

      $nameParts = preg_split('/\s+/', trim($data['name']), 2);
      if ($nameParts === false || $nameParts[0] === '' || ($nameParts[1] ?? '') === '') {
          throw ValidationException::withMessages(['name' => 'Enter your first and last name.']);
      }

      $emailExists = collect(Account::classes())->contains(
          fn (string $model): bool => $model::query()->where('email', $data['email'])->exists(),
      );
      if ($emailExists) {
          throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
      }

      ResidentUser::query()->create([
          'first_name' => $nameParts[0],
          'last_name' => $nameParts[1],
          'email' => $data['email'],
          'contact_number' => $data['contact'],
          'street' => $data['address'],
          'password' => $data['password'],
          'status' => 'active',
          'registration_status' => 'pending',
      ]);

      return response()->json([
          'message' => 'Registration submitted. Your account is waiting for review.',
      ], 201);
    }

    public function authenticate(Request $request): \Illuminate\Http\JsonResponse
    {
      $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
        'device_name' => ['required', 'string'],
    ]);

      $user = User::findByEmail($request->email);

      if (!$user || !Hash::check($request->password, $user->password)) {
          return response()->json([
              'message' => 'Invalid credentials',
          ], 401);
      }

      if (
          $user->status !== 'active'
          || !$user->hasRole('resident', 'driver/responder')
      ) {
          return response()->json([
              'message' => 'This account cannot access the mobile app.',
          ], 403);
      }

      $token = $user->createToken(
          $request->device_name
      )->plainTextToken;

      return response()->json([
          'token' => $token,
          'user' => $user,
      ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
