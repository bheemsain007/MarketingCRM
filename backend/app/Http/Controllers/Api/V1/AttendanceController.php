<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceBreakService;
use App\Services\Attendance\AttendancePingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client-side attendance signals (FR-ATT-02, FR-ATT-04): the heartbeat a page
 * sends to prove someone is present with no lead action to hang a ping on, and
 * explicit break start/stop. Thin - AttendancePingService and
 * AttendanceBreakService own the rules (ARCHITECTURE §2).
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendancePingService $pings,
        private readonly AttendanceBreakService $breaks,
    ) {}

    public function ping(Request $request): JsonResponse
    {
        $this->pings->recordHeartbeat($request->user()->id);

        return ApiResponse::success(message: 'Activity recorded.');
    }

    public function startBreak(Request $request): JsonResponse
    {
        $session = $this->breaks->start($request->user());

        return ApiResponse::success([
            'work_session_id' => $session->id,
            'break_started_at' => $session->break_started_at?->toIso8601String(),
        ], 'Break started.');
    }

    public function stopBreak(Request $request): JsonResponse
    {
        $session = $this->breaks->stop($request->user());

        return ApiResponse::success([
            'work_session_id' => $session->id,
            'break_seconds' => $session->break_seconds,
        ], 'Break ended.');
    }
}
