<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogMutation
{
    // Routes that already get a dedicated, more accurate ActivityLog entry elsewhere (login via
    // the Login event, logout via the Logout event — see AppServiceProvider), or that aren't a
    // meaningful mutation of app state on their own (an OTP resend, a not-yet-authenticated
    // password check). Logging them here too would either duplicate the real entry or add noise
    // no admin reading the activity log actually wants.
    private const SKIP_ROUTES = ['otp.verify', 'otp.resend', 'login.attempt', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $routeName = $request->route()?->getName();

        // Only log state-changing requests from authenticated users.
        if (! auth()->check() || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']) || in_array($routeName, self::SKIP_ROUTES, true)) {
            return $response;
        }

        $action = $routeName
            ?? ($request->method() . ':' . $request->path());

        ActivityLog::record($action, $request, [
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
