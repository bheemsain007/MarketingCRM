<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Enums\ErrorCode;
use App\Exceptions\DncSuppressedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Template;
use App\Services\Dnc\DncService;
use App\Services\Messaging\MessageDriverManager;
use App\Services\Messaging\OutboundMessageService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Outbound messaging (Phase 13, FR-COMM-01..06).
 *
 * Channel-agnostic on purpose. Email is the only live provider today; SMS,
 * WhatsApp, RCS and Voice arrive as additional drivers behind
 * `MessageDriverManager` and need nothing here to change.
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly OutboundMessageService $outbound,
        private readonly MessageDriverManager $drivers,
        private readonly DncService $dnc,
    ) {}

    /** A lead's message history, in the caller's scope. */
    public function index(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['channel', 'status', 'direction', 'campaign_id'],
            allowedSorts: ['created_at', 'sent_at'],
            allowedIncludes: ['template', 'user'],
        );

        $query = $lead->messages()->with(['template:id,name,code', 'user:id,name'])->latest('created_at');

        return ApiResponse::paginated(
            MessageResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Messages retrieved.',
        );
    }

    /**
     * Sends one message to one lead.
     *
     * A suppressed lead produces a recorded `skipped` message AND a 422. Both
     * matter: the skip is the audit trail (BR-DNC-05) and the error is what
     * stops a telecaller assuming it went out.
     */
    public function store(StoreMessageRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $channel = Channel::from($request->string('channel')->toString());

        $template = $request->filled('template_id')
            ? Template::findOrFail($request->integer('template_id'))
            : null;

        if ($template !== null && $template->channel !== $channel) {
            return ApiResponse::error(
                ErrorCode::ValidationFailed,
                'That template belongs to a different channel.',
            );
        }

        $message = $this->outbound->queue(
            $lead,
            $channel,
            $request->only(['subject', 'body']),
            $request->user()->id,
            $template,
        );

        if ($message->status === 'skipped') {
            throw new DncSuppressedException(
                $lead->id,
                $channel,
                $this->dnc->activeEntriesFor($lead)->first()?->reason,
            );
        }

        return ApiResponse::accepted(
            new MessageResource($message),
            $this->drivers->isLive($channel)
                ? 'Message queued for sending.'
                : 'Message queued. No provider is configured for this channel yet, so it will be recorded but not delivered.',
        );
    }

    /**
     * Cross-lead message history, scoped.
     *
     * The reporting view - "what went out this week, and how much of it
     * failed" - which a per-lead endpoint cannot answer.
     */
    public function history(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['channel', 'status', 'direction', 'campaign_id', 'lead_id'],
            allowedSorts: ['created_at', 'sent_at'],
            allowedIncludes: ['template', 'user'],
        );

        $query = Message::query()->with(['template:id,name,code', 'user:id,name']);

        // Scoped through the lead, exactly as the DNC list is - message history
        // is lead data and a telecaller sees their own book (SEC-AUTHZ-03).
        $query->whereHas('lead', fn ($q) => $request->user()->applyDataScope($q));

        if (! $request->filled('sort')) {
            $query->latest('created_at');
        }

        return ApiResponse::paginated(
            MessageResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Messages retrieved.',
        );
    }
}
