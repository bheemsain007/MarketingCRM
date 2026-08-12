<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LostReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityResource;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Services\Sales\OpportunityService;
use App\Services\Sales\SaleService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Opportunities and sales (Phase 22, FR-SALE-01/02/05).
 *
 * Scoped through the lead, like follow-ups and messages - an opportunity is
 * reachable exactly when its lead is, so `LeadPolicy` remains the single
 * implementation of who sees what (SEC-AUTHZ-03/04).
 */
class OpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunityService $opportunities,
        private readonly SaleService $sales,
    ) {}

    /** The pipeline view - open deals across the caller's scope. */
    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'owner_id', 'lead_id', 'customer_id', 'lost_reason'],
            allowedSorts: ['created_at', 'value', 'expected_close_on'],
            allowedIncludes: ['lead', 'owner', 'products', 'sale'],
        );

        $query = Opportunity::query()
            ->with(['lead:id,name', 'owner:id,name'])
            ->whereHas('lead', fn ($q) => $request->user()->applyDataScope($q));

        if (! $request->filled('sort')) {
            $query->latest('created_at');
        }

        return ApiResponse::paginated(
            OpportunityResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Opportunities retrieved.',
        );
    }

    public function forLead(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        return ApiResponse::success(
            OpportunityResource::collection(
                $lead->opportunities()->with(['products.product:id,name', 'owner:id,name', 'sale'])->latest()->get()
            ),
            'Opportunities retrieved.',
        );
    }

    public function store(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'expected_close_on' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'products' => ['nullable', 'array'],
            'products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['nullable', 'integer', 'min:1'],
            'products.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $opportunity = $this->opportunities->create($lead, $validated, $request->user()->id);

        return ApiResponse::created(
            new OpportunityResource($opportunity->load(['products.product:id,name'])),
            'Opportunity created.',
        );
    }

    public function addProduct(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorizeOpportunity($opportunity);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        return ApiResponse::success(
            new OpportunityResource(
                $this->opportunities
                    ->addProduct($opportunity, $validated['product_id'], $validated)
                    ->load(['products.product:id,name'])
            ),
            'Product added.',
        );
    }

    public function removeProduct(Request $request, Opportunity $opportunity, int $productId): JsonResponse
    {
        $this->authorizeOpportunity($opportunity);

        return ApiResponse::success(
            new OpportunityResource(
                $this->opportunities->removeProduct($opportunity, $productId)->load(['products.product:id,name'])
            ),
            'Product removed.',
        );
    }

    /** FR-SALE-05: a lost deal records WHY, from a closed list. */
    public function markLost(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorizeOpportunity($opportunity);

        $validated = $request->validate([
            'reason' => ['required', Rule::enum(LostReason::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success(
            new OpportunityResource($this->opportunities->markLost(
                $opportunity,
                LostReason::from($validated['reason']),
                $validated['notes'] ?? null,
                $request->user()->id,
            )),
            'Opportunity marked lost.',
        );
    }

    /**
     * Records the sale - the act that creates the Customer (BR-CUST-01) and
     * unblocks `Converted`.
     */
    public function recordSale(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorizeOpportunity($opportunity);

        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'quotation_id' => ['nullable', 'integer', 'exists:quotations,id'],
            'sold_by' => ['nullable', 'integer', 'exists:users,id'],
            'sold_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $sale = $this->sales->record($opportunity, $validated, $request->user()->id);

        return ApiResponse::created([
            'id' => $sale->id,
            'reference' => $sale->reference,
            'amount' => $sale->amount,
            'currency' => $sale->currency,
            'customer' => $sale->customer?->only(['id', 'name']),
            'sold_at' => $sale->sold_at->toIso8601String(),
        ], 'Sale recorded. The lead can now be marked converted.');
    }

    private function authorizeOpportunity(Opportunity $opportunity): void
    {
        abort_if($opportunity->lead === null, 404);

        $this->authorize('view', $opportunity->lead);
    }
}
