<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogMutation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log state-changing requests from authenticated users.
        if (! auth()->check() || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $response;
        }

        $action = $request->route()?->getName()
            ?? ($request->method() . ':' . $request->path());

        ActivityLog::record($action, $request, [
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
