<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calls\RecordCallOutcomeRequest;
use App\Http\Requests\Calls\StoreCallRequest;
use App\Http\Resources\CallResource;
use App\Models\Call;
use App\Models\Lead;
use App\Services\Calls\CallService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calling (Phase 9 — FR-CALL-01..05, FR-CALL-08).
 *
 * The Web CRM orchestrates; the device dials (ADR-B). `store` returns the call
 * record the client uses as its dial intent, and the outcome comes back to
 * `update` when the handset is done.
 */
class CallController extends Controller
{
    public function __construct(private readonly CallService $calls) {}

    /**
     * Whether this lead can be dialled right now.
     *
     * Lets a UI grey out the call button with a reason attached, instead of
     * letting a telecaller discover the refusal by being refused.
     */
    public function callability(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        return ApiResponse::success($this->calls->callability($lead));
    }

    public function index(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'user_id', 'dial_source', 'started_at'],
            allowedSorts: ['started_at', 'duration_seconds'],
        );

        $query = $lead->calls()->getQuery()->with('user');

        $calls = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(CallResource::collection($calls), 'Calls retrieved.');
    }

    /**
     * Starts a call, or logs one that already happened.
     *
     * `201` for a logged call (it is finished) and `202` for a dial intent
     * (the work is still to come) - the distinction tells a client whether to
     * wait for an outcome.
     */
    public function store(StoreCallRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('call', $lead);

        $outcome = $request->outcome();

        $call = $outcome !== null
            ? $this->calls->log($lead, $request->user(), $outcome, $request->validated())
            : $this->calls->initiate($lead, $request->user(), $request->validated());

        $call->load('user');

        return $outcome !== null
            ? ApiResponse::created(new CallResource($call), 'Call logged.')
            : ApiResponse::accepted(new CallResource($call), 'Call started. Report the outcome when it ends.');
    }

    public function update(RecordCallOutcomeRequest $request, Call $call): JsonResponse
    {
        $this->authorize('recordOutcome', $call);

        $call = $this->calls->recordOutcome(
            $call,
            $request->outcome(),
            $request->validated(),
            $request->user(),
        );

        $call->load('user');

        return ApiResponse::success(new CallResource($call), 'Call outcome recorded.');
    }

    /**
     * Call history across leads (FR-CALL-04).
     *
     * Scoped like every other list: a telecaller sees their own calls, a
     * manager their team's (SEC-AUTHZ-03).
     */
    public function history(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Call::class);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'user_id', 'lead_id', 'dial_source', 'started_at'],
            allowedSorts: ['started_at', 'duration_seconds'],
        );

        $query = Call::query()
            ->with(['user', 'lead'])
            ->where('tenant_id', config('crm.default_tenant_id'));

        // Calls belong to the telecaller who made them, so the scope applies to
        // `user_id` through the `user` relation, not the lead's owner.
        $request->user()->applyDataScope($query, 'user_id', 'user');

        if (! $request->filled('sort')) {
            $query->latest('started_at')->orderByDesc('id');
        }

        $calls = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(CallResource::collection($calls), 'Call history retrieved.');
    }
}
