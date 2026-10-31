<?php

namespace App\Models;

use InvalidArgumentException;

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

        return $model::query()->find((int) substr($key, $separator + 1));
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
