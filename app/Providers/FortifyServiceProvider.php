<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Login is fully custom now (email+password → email OTP → session), routed in
        // routes/web.php via App\Http\Controllers\Auth\LoginController. Fortify's own
        // AttemptToAuthenticate pipeline calls Auth::login() straight off a valid password,
        // which is exactly the step OTP needs to sit in front of — so its routes are disabled
        // here rather than reused. Fortify itself stays installed for PasswordValidationRules
        // and the 'login'/'two-factor' rate limiter names it established.
        Fortify::ignoreRoutes();

        // Rate limiters for 'login' and 'two-factor' are defined in
        // AppServiceProvider::configureRateLimiters() — do NOT redefine them here.
        // FortifyServiceProvider boots after AppServiceProvider; a duplicate
        // RateLimiter::for('login') call here would overwrite the dual-key limiter
        // (email+IP AND IP-only) with a single-key version, silently killing the
        // per-IP brute-force cap.
    }
}
