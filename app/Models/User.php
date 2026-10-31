<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

abstract class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected $appends = ['full_name', 'role', 'account_key'];

    public $incrementing = false;

    protected $keyType = 'int';

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->getKey() !== null) {
                return;
            }

            $user->setAttribute('id', DB::transaction(function (): int {
                $sequence = DB::table('account_id_sequences')
                    ->where('id', 1)
                    ->lockForUpdate()
                    ->first();

                if ($sequence === null) {
                    throw new \RuntimeException('The account ID sequence has not been initialized.');
                }

                DB::table('account_id_sequences')->where('id', 1)->increment('next_id');

                return (int) $sequence->next_id + 1;
            }));
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'email_verified_at' => 'datetime',
        ];
    }

    abstract public static function roleName(): string;

    protected static function newFactory(): Factory
    {
        return match (static::roleName()) {
            'admin' => \Database\Factories\AdminUserFactory::new(),
            'dispatcher' => \Database\Factories\DispatcherUserFactory::new(),
            'driver/responder' => \Database\Factories\DriverResponderUserFactory::new(),
            'resident' => \Database\Factories\ResidentUserFactory::new(),
        };
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }

    protected function role(): Attribute
    {
        return Attribute::get(fn (): string => static::roleName());
    }

    protected function accountKey(): Attribute
    {
        return Attribute::get(fn (): string => Account::keyFor($this));
    }

    public function getAuthIdentifier(): string
    {
        return Account::keyFor($this);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array(static::roleName(), $roles, true);
    }

    public static function findByEmail(string $email): ?self
    {
        $matches = collect(Account::classes())
            ->map(fn (string $model): ?self => $model::query()->where('email', $email)->first())
            ->filter()
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public static function findByAccountKey(string $key): ?self
    {
        return Account::findByKey($key);
    }

}
