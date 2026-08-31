<?php

namespace App\Providers;

use App\Support\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('activate', function (Request $request) {
            $customerCode = Str::upper(trim((string) $request->input('customer_code')));
            $key = $request->ip().'|'.sha1($customerCode);

            return Limit::perMinute((int) config('activation.activate_per_minute'))
                ->by($key)
                ->response(fn () => ApiResponse::error(
                    'RATE_LIMITED',
                    'Too many activation attempts. Please try again later.',
                    429,
                ));
        });
    }
}
