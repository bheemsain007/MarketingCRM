<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Services\Sales\QuotationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quotations and discount approval (Phase 22, FR-SALE-03/04, BR-SALE-03).
 *
 * Approval carries `discounts.approve` - Manager and above - which is already
 * in `Permission::isAudited()`, so the middleware records the access before the
 * service records the decision.
 */
class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function index(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorizeOpportunity($opportunity);

        return ApiResponse::success(
            $opportunity->quotations()->with(['items', 'approvedBy:id,name'])->latest()->get()->map(
                fn (Quotation $q) => $this->present($q)
            ),
            'Quotations retrieved.',
        );
    }

    public function store(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->authorizeOpportunity($opportunity);

        $validated = $request->validate([
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'valid_until' => ['nullable', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $quotation = $this->quotations->draft($opportunity, $validated, $request->user()->id);

        return ApiResponse::created(
            $this->present($quotation->load('items')),
            $quotation->status->value === 'pending_approval'
                ? 'Quotation drafted. The discount exceeds the threshold and needs approval before it can be issued.'
                : 'Quotation drafted.',
        );
    }

    public function approve(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotation($quotation);

        return ApiResponse::success(
            $this->present($this->quotations->approve($quotation, $request->user())),
            'Discount approved.',
        );
    }

    public function reject(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotation($quotation);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        return ApiResponse::success(
            $this->present($this->quotations->reject($quotation, $request->user(), $validated['reason'])),
            'Discount rejected.',
        );
    }

    public function issue(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotation($quotation);

        return ApiResponse::success(
            $this->present($this->quotations->issue($quotation)),
            'Quotation issued.',
        );
    }

    /** @return array<string, mixed> */
    private function present(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id,
            'number' => $quotation->number,
            'status' => $quotation->status->value,
            'status_label' => $quotation->status->label(),
            'subtotal' => $quotation->subtotal,
            'discount_percent' => $quotation->discount_percent,
            'discount_amount' => $quotation->discount_amount,
            'total' => $quotation->total,
            'currency' => $quotation->currency,
            'valid_until' => $quotation->valid_until?->toDateString(),
            'notes' => $quotation->notes,
            // Surfaced so a UI can explain why the issue button is disabled
            // rather than letting the user discover it through a 422.
            'needs_approval' => $this->quotations->needsApproval((float) $quotation->discount_percent),
            'approved_by' => $quotation->approvedBy?->only(['id', 'name']),
            'approved_at' => $quotation->approved_at?->toIso8601String(),
            'rejection_reason' => $quotation->rejection_reason,
            'items' => $quotation->relationLoaded('items')
                ? $quotation->items->map(fn ($item) => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ])
                : null,
        ];
    }

    private function authorizeOpportunity(Opportunity $opportunity): void
    {
        abort_if($opportunity->lead === null, 404);

        $this->authorize('view', $opportunity->lead);
    }

    private function authorizeQuotation(Quotation $quotation): void
    {
        $this->authorizeOpportunity($quotation->opportunity ?? abort(404));
    }
}
