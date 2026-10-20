<?php

namespace App\Providers;

use App\Services\Nida\MockNidaService;
use App\Services\Nida\NidaServiceInterface;
use App\Services\PaymentProviders\PaymentProviderAdapterInterface;
use App\Services\PaymentProviders\SelcomProvider;
use App\Services\Sms\LogSmsDriver;
use App\Services\Sms\SmsManager;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind SMS Service
        $this->app->singleton(SmsServiceInterface::class, function ($app) {
            return new SmsManager($app);
        });

        // Bind NIDA Service
        $this->app->singleton(NidaServiceInterface::class, function () {
            return new MockNidaService();
        });

        // Bind the Selcom adapter as the single licensed payment partner (M05).
        // All collections, payouts and refunds run through Selcom; rail codes
        // (M-Pesa, Tigo Pesa, Mixx, Airtel, TanQR, banks) are channels within it.
        $this->app->singleton(PaymentProviderAdapterInterface::class, function () {
            return new SelcomProvider(app(\App\Services\QrDepositService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Global API rate limit: 60 per minute
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Registration rate limit: 5 per minute per IP
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Login rate limit: 5 per minute per email + IP
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));
            return Limit::perMinute(5)->by($request->ip() . '|' . $email);
        });

        // OTP verification rate limit: 5 per minute per IP
        RateLimiter::for('otp_verify', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // OTP resend rate limit: 3 per hour + 1 per minute cooldown
        RateLimiter::for('otp_resend', function (Request $request) {
            $identifier = (string) ($request->input('identifier') ?? $request->input('email') ?? $request->input('phone') ?? $request->ip());
            return [
                Limit::perMinute(1)->by($request->ip() . '|' . $identifier),
                Limit::perHour(3)->by($request->ip() . '|' . $identifier),
            ];
        });
    }
}
