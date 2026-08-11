<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\UpdateLeadStatusRequest;
use App\Http\Resources\LeadResource;
use App\Http\Resources\LeadStatusHistoryResource;
use App\Models\Lead;
use App\Services\Leads\LeadStatusService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lead status (Phase 7 — FR-STAT-01..03, BR-STAT-01..05).
 *
 * Status is not part of `PATCH /leads/{id}`. It moves only through this
 * endpoint, because every change has to run the transition matrix, check
 * authority, write history and — for `Not Interested` — write suppression.
 * A status reachable from the generic update body would skip all four.
 */
class LeadStatusController extends Controller
{
    public function __construct(private readonly LeadStatusService $status) {}

    public function update(UpdateLeadStatusRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('changeStatus', $lead);

        // The reopen permission is checked here as well as in the service.
        // The service check is the one that cannot be bypassed; this one gives
        // the caller a clean 403 before any work starts.
        if ($lead->status->isReopenFrom()) {
            $this->authorize('reopen', $lead);
        }

        $lead = $this->status->change(
            $lead,
            $request->status(),
            $request->user(),
            $request->source(),
            $request->input('reason'),
        );

        $lead->load(['source', 'assignedUser', 'tags', 'leadProducts.product']);

        return ApiResponse::success(new LeadResource($lead), 'Lead status updated.');
    }

    /**
     * Where this lead may go next, for this caller.
     *
     * Exists so a client can render only the buttons that will work, rather
     * than discovering the matrix one `422` at a time.
     */
    public function transitions(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $available = $this->status->availableTransitions($lead, $request->user());

        return ApiResponse::success([
            'current' => [
                'status' => $lead->status->value,
                'label' => $lead->status->label(),
                'is_terminal' => $lead->status->isTerminal(),
                'is_closed' => $lead->status->isClosed(),
            ],
            'available' => array_map(fn (LeadStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                // Flags the moves that will demand a reason and a manager.
                'is_reopen' => $lead->status->isReopenFrom(),
            ], $available),
        ]);
    }

    /**
     * The append-only audit (BR-STAT-03).
     *
     * Separate from the general timeline: this is the record consulted when a
     * conversion is disputed, and it answers precisely who moved the lead,
     * when, from what, and why.
     */
    public function history(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['to_status', 'from_status', 'source_channel', 'changed_by', 'created_at'],
            allowedSorts: ['created_at'],
        );

        $query = $lead->statusHistory()->getQuery()->with('changedBy');

        if (! $request->filled('sort')) {
            $query->latest('created_at')->orderByDesc('id');
        }

        $history = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(
            LeadStatusHistoryResource::collection($history),
            'Status history retrieved.',
        );
    }
}
