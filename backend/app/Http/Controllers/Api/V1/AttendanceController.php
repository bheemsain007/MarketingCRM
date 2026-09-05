<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserWorkSession;
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

    /**
     * Whether the caller has an open session and is on a break right now.
     *
     * Nothing else answers this, so a page reload had no way to know which
     * control to draw - a header toggle would flip to "Start break" on every
     * refresh even mid-break without this.
     */
    public function status(Request $request): JsonResponse
    {
        $session = UserWorkSession::open()
            ->where('user_id', $request->user()->id)
            ->latest('started_at')
            ->first();

        return ApiResponse::success([
            'has_open_session' => $session !== null,
            'is_on_break' => $session?->isOnBreak() ?? false,
            'break_started_at' => $session?->break_started_at?->toIso8601String(),
        ]);
    }

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
