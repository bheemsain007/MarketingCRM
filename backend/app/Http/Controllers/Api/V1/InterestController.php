<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Enums\InterestSignalType;
use App\Enums\LeadTemperature;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Services\Interest\InterestEngine;
use App\Services\Interest\LeadScorer;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Interest signals and the interested-lead views (Phase 20, FR-INT-01..03).
 *
 * The manual CRM action of BR-INT-01's eight sources. The other seven arrive
 * from their own modules calling `InterestEngine` directly, which is the point
 * of there being one engine.
 */
class InterestController extends Controller
{
    public function __construct(
        private readonly InterestEngine $engine,
        private readonly LeadScorer $scorer,
    ) {}

    public function store(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(InterestSignalType::class)],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'channel' => ['nullable', Rule::enum(Channel::class)],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            // BR-INT-04. Only meaningful on an AI-detected signal.
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $signal = $this->engine->record(
            $lead,
            InterestSignalType::from($validated['type']),
            $request->user(),
            $validated,
        );

        $fresh = $lead->fresh();

        return ApiResponse::created([
            'signal' => [
                'id' => $signal->id,
                'type' => $signal->type->value,
                'points_awarded' => $signal->points_awarded,
                // False means it was recorded but not acted on - an AI signal
                // below the confidence threshold (BR-INT-04). Surfaced so the
                // caller is not left wondering why nothing moved.
                'acted_on' => $signal->acted_on,
            ],
            'lead' => [
                'id' => $fresh->id,
                'status' => $fresh->status?->value,
                'score' => $fresh->score,
                'temperature' => $fresh->temperature?->value,
            ],
        ], 'Signal recorded.');
    }

    /**
     * Why this lead has the score it has (BR-SCORE-01).
     *
     * The requirement is that a telecaller can see *why* a lead is Hot. A bare
     * number cannot answer that, and a lead nobody understands the score of is
     * a lead nobody trusts the score of.
     */
    public function explain(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $breakdown = $this->scorer->explain($lead);

        return ApiResponse::success([
            'score' => $breakdown['score'],
            // Before clamping, so a lead sitting well above 100 is visible as
            // such rather than looking identical to one exactly at it.
            'raw' => $breakdown['raw'],
            'temperature' => $this->scorer->temperature($lead, $breakdown['score'])->value,
            'last_engagement_at' => $lead->last_engagement_at?->toIso8601String(),
            'decay' => $breakdown['decay'],
            'signals' => $breakdown['lines'],
        ], 'Score breakdown retrieved.');
    }

    /**
     * The maintained views (FR-INT-03): all interested, hot, warm, and
     * product-wise.
     *
     * One endpoint with filters rather than four, because they differ only by
     * predicate - four endpoints would be four places to fix a scoping bug.
     */
    public function interested(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['temperature', 'status', 'assigned_to', 'lead_source_id'],
            allowedSorts: ['score', 'last_engagement_at', 'created_at'],
            allowedIncludes: ['assignedUser', 'leadProducts.product'],
        );

        $query = Lead::query()
            ->with(['assignedUser:id,name'])
            ->whereHas('interestSignals', fn ($q) => $q->where('acted_on', true)
                ->whereIn('type', array_map(
                    fn (InterestSignalType $t) => $t->value,
                    array_filter(
                        InterestSignalType::cases(),
                        fn (InterestSignalType $t) => $t->indicatesInterest(),
                    ),
                )));

        $request->user()->applyDataScope($query);

        // Product-wise interested leads.
        if ($productId = $request->query('product_id')) {
            $query->whereHas('leadProducts', fn ($q) => $q
                ->where('product_id', $productId)
                ->where('interest_status', 'interested'));
        }

        if ($temperature = $request->query('temperature')) {
            $query->where('temperature', LeadTemperature::from($temperature)->value);
        }

        if (! $request->filled('sort')) {
            // Hottest first: the list exists to be worked from the top.
            $query->orderByDesc('score')->orderByDesc('last_engagement_at');
        }

        return ApiResponse::paginated(
            LeadResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Interested leads retrieved.',
        );
    }
}
