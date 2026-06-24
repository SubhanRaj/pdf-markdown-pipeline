<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));

        // Rate limiters for 'login' and 'two-factor' are defined in
        // AppServiceProvider::configureRateLimiters() — do NOT redefine them here.
        // FortifyServiceProvider boots after AppServiceProvider; a duplicate
        // RateLimiter::for('login') call here would overwrite the dual-key limiter
        // (email+IP AND IP-only) with a single-key version, silently killing the
        // per-IP brute-force cap.
    }
}
