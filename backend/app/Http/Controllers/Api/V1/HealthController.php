<?php

namespace App\Http\Controllers\Api\V1;

use App\Console\Commands\SchedulerHeartbeatCommand;
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

    /**
     * Scheduler liveness, on its own endpoint (DEPLOYMENT §5, §9).
     *
     * Separate from `index()` on purpose. A dead cron is a real outage - two
     * retention purges and every follow-up reminder stop (SEC-PII-05,
     * FR-FUP-03) - but it is a DIFFERENT outage from "the site is down", and
     * folding it into the main health check would have an uptime monitor report
     * the whole service offline while every page still serves perfectly. Two
     * signals, two alerts, two things to go and fix.
     *
     * 503 rather than 200-with-a-sad-body, so a monitor that only reads status
     * codes still notices.
     */
    public function scheduler(): JsonResponse
    {
        $last = SchedulerHeartbeatCommand::lastBeat();
        $healthy = SchedulerHeartbeatCommand::isHealthy();

        return ApiResponse::success(
            [
                'status' => $healthy ? 'ok' : 'stale',
                'last_run_at' => $last?->toIso8601String(),
                'stale_after_minutes' => SchedulerHeartbeatCommand::STALE_AFTER_MINUTES,
            ],
            $healthy ? 'Scheduler is running.' : 'Scheduler has not run recently - check the cron entry.',
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
