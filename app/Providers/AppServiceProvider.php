<?php

namespace App\Providers;

use App\Auth\RoleUserProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use App\Models\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('role-users', fn ($app, array $config) => new RoleUserProvider($app['hash']));
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
