<?php

namespace App\Auth;

use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher;

class RoleUserProvider implements UserProvider
{
    public function __construct(private Hasher $hasher)
    {
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        if (is_int($identifier) || (is_string($identifier) && ctype_digit($identifier))) {
            return Account::findById((int) $identifier);
        }

        return is_string($identifier) ? Account::findByKey($identifier) : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        $user = $this->retrieveById($identifier);

        if (!$user instanceof User) {
            return null;
        }

        $rememberToken = $user->getRememberToken();

        return $rememberToken !== null && hash_equals($rememberToken, $token) ? $user : null;
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        $user->setRememberToken($token);
        $timestamps = $user->timestamps;
        $user->timestamps = false;
        $user->save();
        $user->timestamps = $timestamps;
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;

        if (!is_string($email) || $email === '') {
            return null;
        }

        $matches = collect(Account::classes())
            ->map(fn (string $model): ?User => $model::query()
                ->where('email', $email)
                ->when(isset($credentials['status']), fn ($query) => $query->where('status', $credentials['status']))
                ->first())
            ->filter()
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        return isset($credentials['password'])
            && $this->hasher->check($credentials['password'], $user->getAuthPassword());
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[\SensitiveParameter] array $credentials,
        bool $force = false,
    ): void {
        if (! $user instanceof User || (! $force && ! $this->hasher->needsRehash($user->getAuthPassword()))) {
            return;
        }

        $user->forceFill([
            $user->getAuthPasswordName() => $this->hasher->make($credentials['password']),
        ])->save();
    }
}
