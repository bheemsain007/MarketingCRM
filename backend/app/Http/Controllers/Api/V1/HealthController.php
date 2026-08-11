<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Health check for monitoring (DEPLOYMENT §9).
 *
 * Deliberately exposes no business data and no version/environment detail that
 * would help an attacker - it is an unauthenticated endpoint.
 *
 * Returns 503 when a dependency is down so an uptime monitor treats it as an
 * outage rather than a success with a sad message in the body.
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo()),
        ];

        $healthy = ! in_array(false, $checks, true);

        return ApiResponse::success(
            [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
                'time' => now()->toIso8601String(),
            ],
            $healthy ? 'Service healthy.' : 'Service degraded.',
            $healthy ? 200 : 503,
        );
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            // The reason is logged by the framework; it is not returned to an
            // unauthenticated caller.
            return false;
        }
    }
}
