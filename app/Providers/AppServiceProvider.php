<?php

namespace App\Providers;

use App\Models\Department;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configureRouteBindings();
    }

    private function configureRouteBindings(): void
    {
        // Resolves {department} scoped by the {level} URL alias so that slugs
        // shared across levels (e.g. "excise" at dept + secretariat) always
        // resolve to the correct record.
        Route::bind('department', function (string $slug) {
            $alias = request()->route('level');
            $level = match($alias) {
                'sectt' => 'secretariat_level',
                default => 'department_level',
            };

            return Department::where('slug', $slug)
                ->where('level', $level)
                ->firstOrFail();
        });
    }

    private function configureRateLimiters(): void
    {
        // ── Login brute-force protection ──────────────────────────────────────
        // Keyed by email+IP (targeted) AND IP alone (broad), whichever hits first.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->input('email') . '|' . $request->ip()),
                Limit::perMinute(10)->by($request->ip()),
            ];
        });

        // ── Two-factor brute-force protection ─────────────────────────────────
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->session()->get('login.id') . '|' . $request->ip());
        });

        // ── General authenticated mutations ───────────────────────────────────
        // 60 state-changing requests per minute per user (sanity cap).
        RateLimiter::for('mutations', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip());
        });

        // ── File uploads ──────────────────────────────────────────────────────
        // Tighter cap: 10 uploads per minute per user to prevent disk exhaustion.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
