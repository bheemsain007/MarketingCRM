<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Enums\FollowUpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\FollowUpResource;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Services\FollowUps\FollowUpService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Follow-ups (Phase 21, FR-FUP-01..05).
 *
 * Scoping is through the LEAD, not through `assigned_to`. A telecaller's own
 * book is the unit of visibility everywhere else in this system, and a
 * follow-up assigned to a colleague on a lead I own is still something I need
 * to see - otherwise reassignment makes commitments invisible to the person now
 * holding the relationship.
 */
class FollowUpController extends Controller
{
    public function __construct(private readonly FollowUpService $followUps) {}

    /** Everything due to me - the telecaller's working list. */
    public function mine(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'channel', 'lead_id', 'product_id'],
            allowedSorts: ['scheduled_at', 'created_at'],
            allowedIncludes: ['lead', 'product', 'assignee'],
        );

        $query = FollowUp::query()
            ->with(['lead:id,name', 'product:id,name', 'assignee:id,name'])
            ->where('assigned_to', $request->user()->id);

        if (! $request->filled('sort')) {
            // Soonest first: a follow-up list ordered by creation is useless to
            // the person working it.
            $query->orderBy('scheduled_at');
        }

        if (! $request->has('filter.status')) {
            // Default to what still needs doing. Missed counts as needing
            // doing - it is late, not void.
            $query->whereIn('status', [FollowUpStatus::Open->value, FollowUpStatus::Missed->value]);
        }

        return ApiResponse::paginated(
            FollowUpResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Follow-ups retrieved.',
        );
    }

    /** One lead's full follow-up history, reschedules included (FR-FUP-04). */
    public function index(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $followUps = $lead->followUps()
            ->with(['product:id,name', 'assignee:id,name', 'completedBy:id,name'])
            ->orderByDesc('scheduled_at')
            ->get();

        return ApiResponse::success(FollowUpResource::collection($followUps), 'Follow-ups retrieved.');
    }

    public function store(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $validated = $request->validate([
            // Future-dated: a reminder for a time already past fires
            // immediately and reads as a bug to whoever receives it.
            'scheduled_at' => ['required', 'date', 'after:now'],
            'channel' => ['nullable', Rule::in(Channel::values())],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'subject' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $followUp = $this->followUps->schedule($lead, $validated, $request->user()->id);

        return ApiResponse::created(
            new FollowUpResource($followUp->load(['lead:id,name', 'assignee:id,name'])),
            'Follow-up scheduled.',
        );
    }

    public function complete(Request $request, FollowUp $followUp): JsonResponse
    {
        $this->authorizeFollowUp($followUp);

        $validated = $request->validate(['outcome' => ['nullable', 'string', 'max:2000']]);

        return ApiResponse::success(
            new FollowUpResource($this->followUps->complete(
                $followUp,
                $validated['outcome'] ?? null,
                $request->user()->id,
            )),
            'Follow-up completed.',
        );
    }

    public function reschedule(Request $request, FollowUp $followUp): JsonResponse
    {
        $this->authorizeFollowUp($followUp);

        $validated = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Returns the REPLACEMENT, not the original - the original is closed
        // and kept for history (BR-FUP-03).
        return ApiResponse::success(
            new FollowUpResource($this->followUps->reschedule(
                $followUp,
                $validated['scheduled_at'],
                $request->user()->id,
                $validated['notes'] ?? null,
            )),
            'Follow-up rescheduled.',
        );
    }

    public function cancel(Request $request, FollowUp $followUp): JsonResponse
    {
        $this->authorizeFollowUp($followUp);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::success(
            new FollowUpResource($this->followUps->cancel(
                $followUp,
                $validated['reason'] ?? null,
                $request->user()->id,
            )),
            'Follow-up cancelled.',
        );
    }

    /**
     * A follow-up is reachable exactly when its lead is.
     *
     * Delegating to LeadPolicy rather than writing a second rule: two scope
     * implementations eventually disagree, and the disagreement surfaces as a
     * telecaller reading a colleague's commitments (SEC-AUTHZ-04).
     */
    private function authorizeFollowUp(FollowUp $followUp): void
    {
        abort_if($followUp->lead === null, 404);

        $this->authorize('view', $followUp->lead);
    }
}
