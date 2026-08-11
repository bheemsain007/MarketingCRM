<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees API routes are treated as JSON regardless of what the client sent.
 *
 * Without this, a request missing `Accept: application/json` makes Laravel
 * render HTML error pages or issue redirects to a login route - so an API client
 * hitting an auth failure would receive an HTML page instead of the envelope.
 * The Flutter app in particular has no login page to redirect to.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
