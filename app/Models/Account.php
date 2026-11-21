<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class Account
{
    /**
     * @return array<class-string<User>>
     */
    public static function classes(): array
    {
        return [
            AdminUser::class,
            DispatcherUser::class,
            DriverResponderUser::class,
            ResidentUser::class,
        ];
    }

    public static function keyFor(User $user): string
    {
        return self::keyForRoleAndId($user::roleName(), (int) $user->getKey());
    }

    public static function keyForRoleAndId(string $role, int $id): string
    {
        $slug = match ($role) {
            'admin' => 'admin',
            'dispatcher' => 'dispatcher',
            'driver/responder' => 'driver-responder',
            'resident' => 'resident',
        };

        return "{$slug}-{$id}";
    }

    public static function findByKey(string $key): ?User
    {
        $parsed = self::parseKey($key);
        if ($parsed === null) {
            return null;
        }

        [$model, $id] = $parsed;

        return $model::query()->find($id);
    }

    public static function findByKeyWithTrashed(string $key): ?User
    {
        $parsed = self::parseKey($key);
        if ($parsed === null) {
            return null;
        }

        [$model, $id] = $parsed;

        return $model::withTrashed()->find($id);
    }

    /** Serialize account name checks and ID allocation within the current transaction. */
    public static function lockIdSequence(): int
    {
        $sequence = DB::table('account_id_sequences')->where('id', 1)->lockForUpdate()->first();

        if ($sequence === null) {
            throw new RuntimeException('The account ID sequence has not been initialized.');
        }

        return (int) $sequence->next_id;
    }

    public static function lockForStatusChange(User $user): User
    {
        if ($user instanceof DriverResponderUser) {
            ResponseTeam::query()->whereIn('id', $user->activeMemberships()->reorder()->select('response_team_id'))
                ->orderBy('id')->lockForUpdate()->get(['id']);
        }

        return $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    public static function ensureStatusChangeAllowed(User $user, string $status): void
    {
        if ($status !== 'active' && $status !== $user->status && $user instanceof DriverResponderUser && $user->hasActiveEmergencyDispatch()) {
            throw ValidationException::withMessages([
                'status' => 'Cannot deactivate this user because they are currently dispatched to an emergency.',
            ]);
        }
    }

    /**
     * @return array{0: class-string<User>, 1: int}|null
     */
    private static function parseKey(string $key): ?array
    {
        $separator = strrpos($key, '-');

        if ($separator === false || !ctype_digit(substr($key, $separator + 1))) {
            return null;
        }

        $model = match (substr($key, 0, $separator)) {
            'admin' => AdminUser::class,
            'dispatcher' => DispatcherUser::class,
            'driver-responder' => DriverResponderUser::class,
            'resident' => ResidentUser::class,
            default => null,
        };

        if ($model === null) {
            return null;
        }

        return [$model, (int) substr($key, $separator + 1)];
    }

    public static function findById(int $id): ?User
    {
        $matches = collect(self::classes())
            ->map(fn (string $model): ?User => $model::query()->find($id))
            ->filter()
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @return class-string<User>
     */
    public static function modelForRole(string $role): string
    {
        return match ($role) {
            'admin' => AdminUser::class,
            'dispatcher' => DispatcherUser::class,
            'driver/responder' => DriverResponderUser::class,
            'resident' => ResidentUser::class,
            default => throw new InvalidArgumentException("Unknown account role [{$role}]."),
        };
    }
}
