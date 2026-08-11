<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\StoreLeadProductRequest;
use App\Http\Requests\Leads\UpdateLeadProductRequest;
use App\Http\Resources\LeadProductResource;
use App\Models\Lead;
use App\Models\LeadProduct;
use App\Models\Product;
use App\Services\Leads\LeadProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-product interest on a lead (Phase 7 — FR-STAT-05, BR-PROD-01..03).
 *
 * Routes are scope-bound, so `/leads/5/products/9` where product interest 9
 * belongs to a different lead is a `404`, not somebody else's record.
 */
class LeadProductController extends Controller
{
    public function __construct(private readonly LeadProductService $products) {}

    public function index(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $interests = $lead->leadProducts()->with('product')->get();

        return ApiResponse::success(
            LeadProductResource::collection($interests),
            'Product interest retrieved.',
        );
    }

    public function store(StoreLeadProductRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('manageProducts', $lead);

        $product = Product::withTrashed()->findOrFail($request->integer('product_id'));

        $interest = $this->products->attach(
            $lead,
            $product,
            $request->validated(),
            $request->user(),
        );

        $interest->load('product');

        return ApiResponse::created(new LeadProductResource($interest), 'Product interest added.');
    }

    /**
     * Changes interest status, quoted value, or both.
     *
     * The status move runs the transition matrix; the detail fields do not.
     * Keeping them on one endpoint matches how the work actually happens — a
     * telecaller quotes a price and advances the stage in the same breath.
     */
    public function update(UpdateLeadProductRequest $request, Lead $lead, LeadProduct $leadProduct): JsonResponse
    {
        $this->authorize('manageProducts', $lead);

        if ($request->filled('interest_status')) {
            $leadProduct = $this->products->changeInterest(
                $leadProduct,
                LeadStatus::from($request->string('interest_status')->toString()),
                $request->user(),
                $request->input('note'),
            );
        }

        if ($request->hasAny(['quoted_value', 'currency', 'notes'])) {
            $leadProduct = $this->products->updateDetails(
                $leadProduct,
                $request->validated(),
                $request->user(),
            );
        }

        $leadProduct->load('product');

        return ApiResponse::success(new LeadProductResource($leadProduct), 'Product interest updated.');
    }

    public function destroy(Request $request, Lead $lead, LeadProduct $leadProduct): JsonResponse
    {
        $this->authorize('manageProducts', $lead);

        $this->products->detach($leadProduct, $request->user());

        return ApiResponse::success(message: 'Product interest removed.');
    }
}
