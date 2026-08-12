<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadDuplicateCandidateResource;
use App\Models\LeadDuplicateCandidate;
use App\Services\Leads\DuplicateDetector;
use App\Services\Leads\LeadMergeService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The duplicate review queue (BR-DUP-03/04, T-64).
 *
 * BR-DUP-03 says a shared email is "flagged for review, not auto-merged" - this
 * is the review. Nothing here merges automatically; every merge is a person
 * deciding that two records are one human.
 *
 * Merging is gated on `leads.archive` rather than `leads.update`. It is
 * irreversible and it removes a record from circulation, which is the same
 * authority archiving needs - and a telecaller holds neither.
 */
class LeadDuplicateController extends Controller
{
    public function __construct(
        private readonly LeadMergeService $merges,
        private readonly DuplicateDetector $detector,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'match_type'],
            allowedSorts: ['created_at', 'resolved_at'],
            allowedIncludes: ['lead', 'duplicate', 'resolver'],
        );

        $query = LeadDuplicateCandidate::query()->with(['lead', 'duplicate']);

        // Pending by default. A review queue that opens showing everything ever
        // dismissed is one nobody scrolls to the bottom of.
        if (! $request->filled('filter.status')) {
            $query->pending();
        }

        if (! $request->filled('sort')) {
            $query->latest('created_at');
        }

        return ApiResponse::paginated(
            LeadDuplicateCandidateResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Duplicate candidates retrieved.',
        );
    }

    /**
     * Merges one candidate's pair.
     *
     * The caller names which lead survives. There is no sensible default: the
     * older record usually has more history, but the newer one may be the
     * corrected spelling of a name, and only a person looking at both can say.
     */
    public function merge(Request $request, LeadDuplicateCandidate $candidate): JsonResponse
    {
        $data = $request->validate([
            'survivor_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $pair = [$candidate->lead_id, $candidate->duplicate_lead_id];

        if (! in_array((int) $data['survivor_id'], $pair, true)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'The surviving lead must be one of the two in this candidate.',
            );
        }

        $survivor = (int) $data['survivor_id'] === $candidate->lead_id
            ? $candidate->lead
            : $candidate->duplicate;

        $duplicate = (int) $data['survivor_id'] === $candidate->lead_id
            ? $candidate->duplicate
            : $candidate->lead;

        if ($survivor === null || $duplicate === null) {
            throw new ApiException(ErrorCode::ValidationFailed, 'One of these leads no longer exists.');
        }

        $merged = $this->merges->merge($survivor, $duplicate, $request->user(), $data['note'] ?? null);

        $candidate->update([
            'status' => 'merged',
            'resolution_note' => $data['note'] ?? null,
            'resolved_by' => $request->user()?->id,
            'resolved_at' => now(),
        ]);

        return ApiResponse::success(
            ['candidate' => new LeadDuplicateCandidateResource($candidate->fresh()), 'survivor_id' => $merged->id],
            'Leads merged.',
        );
    }

    /**
     * Records that the pair are different people.
     *
     * Kept rather than deleted, and the detector checks dismissed pairs too, so
     * the same question is not asked again on every import.
     */
    public function dismiss(Request $request, LeadDuplicateCandidate $candidate): JsonResponse
    {
        $data = $request->validate([
            // Mandatory. "Why are these not the same person?" is the whole
            // value of a dismissal to whoever reads the queue next.
            'note' => ['required', 'string', 'max:500'],
        ]);

        $candidate->update([
            'status' => 'dismissed',
            'resolution_note' => $data['note'],
            'resolved_by' => $request->user()?->id,
            'resolved_at' => now(),
        ]);

        return ApiResponse::success(
            new LeadDuplicateCandidateResource($candidate->fresh()),
            'Marked as different people.',
        );
    }

    /**
     * Sweeps leads captured before detection existed.
     *
     * A one-off, exposed rather than hidden in a console command, because the
     * person who needs it is the one looking at an empty queue and wondering
     * whether that means "no duplicates" or "never checked".
     */
    public function backfill(): JsonResponse
    {
        return ApiResponse::success(
            ['created' => $this->detector->backfill()],
            'Existing leads swept for shared email addresses.',
        );
    }
}
