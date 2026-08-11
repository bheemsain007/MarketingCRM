<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Leads\LeadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lead notes and activity timeline (FR-LEAD-03, FR-LEAD-09).
 *
 * Notes are append-only: a note records what was said at a point in time, so
 * there is no edit or delete endpoint by design.
 */
class LeadNoteController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function index(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $notes = $lead->notes()->with('user:id,name')->paginate(
            min((int) $request->query('per_page', 25), 100),
        );

        return ApiResponse::paginated($notes->through(fn ($note) => [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $note->user?->only(['id', 'name']),
            'call_id' => $note->call_id,
            'created_at' => $note->created_at?->toIso8601String(),
        ]));
    }

    public function store(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('addNote', $lead);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'call_id' => ['nullable', 'integer', 'exists:calls,id'],
        ]);

        $note = $lead->notes()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'call_id' => $validated['call_id'] ?? null,
        ]);

        // Notes appear on the timeline alongside calls and status changes so
        // the history reads as one story (FR-LEAD-09).
        $this->leads->recordActivity(
            $lead,
            $request->user()->id,
            'note',
            'Note added',
            description: mb_substr($validated['body'], 0, 200),
        );

        return ApiResponse::created([
            'id' => $note->id,
            'body' => $note->body,
            'created_at' => $note->created_at?->toIso8601String(),
        ], 'Note added.');
    }

    /**
     * The unified timeline: calls, messages, status changes, notes, follow-ups,
     * assignments and payments in one chronological read.
     */
    public function timeline(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $activities = $lead->activities()
            ->with('user:id,name')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return ApiResponse::paginated($activities->through(fn ($activity) => [
            'id' => $activity->id,
            'type' => $activity->activity_type,
            'title' => $activity->title,
            'description' => $activity->description,
            'meta' => $activity->meta,
            'actor' => $activity->user?->only(['id', 'name']),
            'occurred_at' => $activity->occurred_at?->toIso8601String(),
        ]));
    }
}
