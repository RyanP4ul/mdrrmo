<?php

namespace App\Providers;

use App\Auth\RoleUserProvider;
use App\Models\EmergencyReport;
use App\Models\Sanctum\PersonalAccessToken;
use App\Models\ServiceSchedule;
use App\Observers\AdminNotificationObserver;
use App\Observers\AutomaticDispatchObserver;
use App\Support\MobileValidation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
        Auth::provider('role-users', fn ($app, array $config) => new RoleUserProvider($app['hash']));
        EmergencyReport::observe(AutomaticDispatchObserver::class);
        EmergencyReport::observe(AdminNotificationObserver::class);
        ServiceSchedule::observe(AdminNotificationObserver::class);
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        RateLimiter::for('mobile-login', function (Request $request): array {
            return [
                Limit::perMinute(5)->by('mobile-login:'.hash('sha256', strtolower((string) $request->input('email')).'|'.$request->ip())),
                Limit::perMinute(30)->by('mobile-login-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('mobile-register', fn (Request $request) => Limit::perHour(10)->by('mobile-register:'.$request->ip()));
        RateLimiter::for('mobile-otp-send', function (Request $request): array {
            $phone = MobileValidation::toE164((string) $request->input('contact'));

            return [
                Limit::perHour(5)->by('mobile-otp-send-phone:'.hash('sha256', $phone)),
                Limit::perHour(15)->by('mobile-otp-send-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('mobile-otp-verify', fn (Request $request) => Limit::perMinute(15)->by('mobile-otp-verify:'.$request->ip()));
        RateLimiter::for('guest-reports', fn (Request $request) => [
            Limit::perMinute(5)->by('guest-reports-minute:'.$request->ip()),
            Limit::perHour(10)->by('guest-reports-hour:'.$request->ip()),
        ]);
    }
}
