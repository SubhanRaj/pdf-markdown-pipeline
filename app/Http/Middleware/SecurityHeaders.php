<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent clickjacking — disallow embedding this app in any frame
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Prevent MIME sniffing — browser must honour the declared Content-Type
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Limit referrer leakage — only origin is sent on cross-origin requests
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Disable browser APIs unused by this application
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        // Content Security Policy
        // unsafe-inline is required for Tailwind Play CDN and inline Blade scripts.
        // Sources are locked to self + known CDNs (jsDelivr, Tailwind CDN).
        // object-src and base-uri are fully locked down.
        //
        // 'unsafe-eval' is added ONLY for /pulse and its Livewire asset/update routes — Pulse's
        // own dashboard components (card.blade.php, theme-switcher.blade.php, etc.) use raw
        // inline Alpine expressions (x-data="{ ... }", @click="...", :class="cond ? a : b") that
        // Alpine evaluates via new Function() at runtime. Livewire's CSP-safe Alpine build avoids
        // eval but can't run those expressions at all (tried 2026-07-25, see config/livewire.php's
        // csp_safe comment) — it's compile-time-directives-only, and Pulse's UI wasn't built for
        // it. Scoping the loosened policy to just these admin-gated routes (rather than app-wide)
        // keeps the rest of the site's CSP as strict as before.
        $needsUnsafeEval = $request->is('pulse') || $request->is('pulse/*') || $request->is('livewire-*');
        $scriptSrc = "script-src 'self' 'unsafe-inline'" . ($needsUnsafeEval ? " 'unsafe-eval'" : '') . " https://cdn.tailwindcss.com https://cdn.jsdelivr.net";

        $csp = implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net",
            "font-src 'self' https://cdn.jsdelivr.net",
            "img-src 'self' data:",
            "frame-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        // HSTS — only sent over HTTPS to avoid breaking HTTP-only local dev
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
