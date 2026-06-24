<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Division;
use App\Models\RuleSet;
use App\Models\Section;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        $this->configureActivityLogging();
    }

    private function configureActivityLogging(): void
    {
        Event::listen(Login::class, function (Login $event) {
            ActivityLog::record('auth.login', request(), [
                'guard' => $event->guard,
            ]);
        });
    }

    private function configureRouteBindings(): void
    {
        // Resolves {department} scoped by the {level} URL alias so that slugs
        // shared across levels (e.g. "excise" at dept + secretariat) always
        // resolve to the correct record.
        // Resolves {rule_set} scoped to the current {department} so slugs are unique per dept.
        // Resolves {division} scoped to the current {section} so slugs are unique per section.
        Route::bind('rule_set', function (string $slug) {
            $dept = request()->route('department');
            return RuleSet::where('slug', $slug)
                ->where('department_id', $dept->id)
                ->firstOrFail();
        });

        // Explicit binding so {section} is always resolved before {division} below.
        Route::bind('section', function (string $slug) {
            $dept = request()->route('department');
            $query = Section::where('slug', $slug);
            if ($dept instanceof Department) {
                $query->where('department_id', $dept->id);
            }
            return $query->firstOrFail();
        });

        Route::bind('division', function (string $slug) {
            $section = request()->route('section');
            if (! $section instanceof Section) {
                abort(404);
            }
            return Division::where('slug', $slug)
                ->where('section_id', $section->id)
                ->firstOrFail();
        });

        Route::bind('department', function (string $slug) {
            $alias = request()->route('level');
            // Explicit match — unknown aliases abort 404 rather than silently
            // falling through to department_level, which could mask routing bugs.
            $level = match($alias) {
                'dept'  => 'department_level',
                'sectt' => 'secretariat_level',
                default => abort(404),
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
        // 20/min per user. Sufficient for bulk initial data-entry batches while
        // capping worst-case disk throughput to 20 × 50 MB = 1 GB/min.
        // Once initial loading is complete and uploads are 1–2 files at a time,
        // tighten to 5–10/min via this single constant.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(20)
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
