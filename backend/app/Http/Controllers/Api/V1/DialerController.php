<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CallResource;
use App\Http\Resources\DialerSessionResource;
use App\Http\Resources\LeadResource;
use App\Models\AutoDialerSession;
use App\Services\Calls\AutoDialerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Auto dialer (Phase 10 — FR-CALL-06/07, BR-CALL-02/03).
 *
 * `next` is the whole module: it decides which lead is next, why the ones
 * before it were passed over, and hands back a dial intent. The device places
 * the call (ADR-B).
 */
class DialerController extends Controller
{
    public function __construct(private readonly AutoDialerService $dialer) {}

    /** The caller's open run, if any — this is what survives a page reload. */
    public function current(Request $request): JsonResponse
    {
        $session = $this->dialer->currentFor($request->user());

        if ($session === null) {
            return ApiResponse::success(null, 'No dialling session is open.');
        }

        $session->load('items');

        return ApiResponse::success(new DialerSessionResource($session));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AutoDialerSession::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(LeadStatus::values())],
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
        ]);

        $session = $this->dialer->start($request->user(), $filters);

        return ApiResponse::created(new DialerSessionResource($session), 'Dialling session started.');
    }

    public function show(Request $request, AutoDialerSession $autoDialerSession): JsonResponse
    {
        $this->authorize('view', $autoDialerSession);

        $autoDialerSession->load('items');

        return ApiResponse::success(new DialerSessionResource($autoDialerSession));
    }

    /**
     * The next dialable lead.
     *
     * Returns the lead, its dial intent, and **the leads passed over on the
     * way there with the reason for each** (FR-CALL-07) — so a telecaller can
     * see that eleven numbers were skipped for cooldown rather than wondering
     * why the queue emptied so fast.
     *
     * `204` means the run is finished.
     */
    public function next(Request $request, AutoDialerSession $autoDialerSession): JsonResponse
    {
        $this->authorize('drive', $autoDialerSession);

        $result = $this->dialer->next($autoDialerSession);

        if ($result['finished']) {
            // The skips come back here too: a run that ends *because*
            // everything left was suppressed or in cooldown is exactly when
            // the telecaller needs to see why.
            return ApiResponse::success([
                'lead' => null,
                'skipped' => $result['skipped'],
                'session' => new DialerSessionResource($autoDialerSession->fresh()),
            ], 'The queue is finished.');
        }

        $result['lead']->load(['source', 'assignedUser']);

        return ApiResponse::success([
            'lead' => new LeadResource($result['lead']),
            'call' => new CallResource($result['call']),
            'position' => $result['item']->position,
            'skipped' => $result['skipped'],
            'session' => new DialerSessionResource($autoDialerSession->fresh()),
        ], 'Next lead ready.');
    }

    public function pause(Request $request, AutoDialerSession $autoDialerSession): JsonResponse
    {
        $this->authorize('drive', $autoDialerSession);

        return ApiResponse::success(
            new DialerSessionResource($this->dialer->pause($autoDialerSession)),
            'Session paused. Your place in the queue is kept.',
        );
    }

    public function resume(Request $request, AutoDialerSession $autoDialerSession): JsonResponse
    {
        $this->authorize('drive', $autoDialerSession);

        return ApiResponse::success(
            new DialerSessionResource($this->dialer->resume($autoDialerSession)),
            'Session resumed.',
        );
    }

    public function stop(Request $request, AutoDialerSession $autoDialerSession): JsonResponse
    {
        $this->authorize('stop', $autoDialerSession);

        return ApiResponse::success(
            new DialerSessionResource($this->dialer->stop($autoDialerSession)),
            'Session stopped.',
        );
    }
}
