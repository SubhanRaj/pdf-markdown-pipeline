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
        // unsafe-eval is required for Alpine.js (2026-07-26) — its directive expressions
        // (x-show, @click, etc.) are evaluated via `new Function()` internally, which CSP
        // blocks without this. Alpine ships a separate eval-free CSP build, but it forces
        // every x-data to be a globally pre-registered Alpine.data() component with a
        // restricted expression syntax — a real rewrite, not a drop-in swap. Given this app
        // already grants unsafe-inline (which permits arbitrary injected <script> content and
        // is the larger of the two holes), unsafe-eval is a comparatively small addition, not
        // a new category of risk. Revisit only if this app's CSP posture needs to get stricter.
        // Sources are locked to self + known CDNs (jsDelivr, Tailwind CDN).
        // object-src and base-uri are fully locked down.
        $scriptSrc = "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net";

        $csp = implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net",
            "font-src 'self' https://cdn.jsdelivr.net",
            "img-src 'self' data:",
            "frame-src 'self'",
            // cdn.jsdelivr.net added 2026-07-26 for the policy page's client-side state-shape
            // icons (fetch() of @svg-maps/india's SVG) — same origin already trusted for
            // script-src/style-src/font-src above, just extended to fetch().
            "connect-src 'self' https://cdn.jsdelivr.net",
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
