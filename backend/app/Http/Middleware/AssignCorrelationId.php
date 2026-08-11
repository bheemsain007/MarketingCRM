<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a correlation ID to every request (ARCHITECTURE §11).
 *
 * The ID is echoed in the response header and pushed into the log context, so a
 * single campaign send can be traced from the HTTP request, through the queued
 * job, to the provider call. Without it, debugging a failed send across
 * asynchronous boundaries means guessing from timestamps.
 *
 * An inbound X-Correlation-ID is honoured so the Flutter app (or a load
 * balancer) can originate the trace.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header(self::HEADER) ?: (string) Str::uuid();

        $request->headers->set(self::HEADER, $correlationId);
        $request->attributes->set('correlation_id', $correlationId);

        Log::shareContext(['correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
