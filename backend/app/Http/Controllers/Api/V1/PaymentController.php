<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\Payments\PaymentService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payments (Phase 23, FR-PAY-01..05).
 *
 * Scoped through the lead like everything else that hangs off one, so
 * `LeadPolicy` stays the single implementation of who sees what.
 *
 * `payments.refund` is a separate permission from `payments.manage` and is
 * already in `Permission::isAudited()` - money going back out is the highest
 * -value action here (SEC-AUTHZ-06).
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'method', 'sale_id', 'customer_id', 'lead_id', 'product_id'],
            allowedSorts: ['created_at', 'due_on', 'paid_at', 'amount'],
            allowedIncludes: ['sale', 'customer', 'product'],
        );

        $query = Payment::query()
            ->with(['customer:id,name', 'product:id,name'])
            ->whereHas('lead', fn ($q) => $request->user()->applyDataScope($q));

        if (! $request->filled('sort')) {
            $query->latest('created_at');
        }

        return ApiResponse::paginated(
            $options->applyTo($query)->paginate($options->perPage()),
            'Payments retrieved.',
        );
    }

    /**
     * A sale's payments plus its derived balance.
     *
     * The balance is computed here rather than stored (BR-PAY-04) - it is the
     * number an argument with a customer turns on, and a stored copy drifts.
     */
    public function forSale(Request $request, Sale $sale): JsonResponse
    {
        $this->authorizeSale($sale);

        return ApiResponse::success([
            'sale' => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'amount' => $sale->amount,
                'currency' => $sale->currency,
            ],
            'collected' => $this->payments->collectedFor($sale),
            'balance' => $this->payments->balanceFor($sale),
            'payments' => $sale->payments()->with(['product:id,name'])->latest()->get(),
        ], 'Payments retrieved.');
    }

    public function store(Request $request, Sale $sale): JsonResponse
    {
        $this->authorizeSale($sale);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            // Required only when the sale covers several products - the service
            // fills it in otherwise (BR-PAY-03, T-58).
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'method' => ['nullable', Rule::in(['cash', 'bank_transfer', 'upi', 'card', 'cheque', 'gateway', 'other'])],
            'due_on' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payment = $this->payments->record($sale, $validated, $request->user()->id);

        return ApiResponse::created([
            'id' => $payment->id,
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'status' => $payment->status->value,
            // Returned with the receipt so the person taking the money can see
            // immediately what is still owed.
            'balance' => $this->payments->balanceFor($sale->fresh()),
        ], 'Payment recorded.');
    }

    /**
     * Moves a payment through the BR-PAY-02 matrix.
     *
     * A refund needs `payments.refund`, checked here rather than on the route
     * because one endpoint serves every transition.
     */
    public function transition(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizePayment($payment);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(PaymentStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $target = PaymentStatus::from($validated['status']);

        if ($target === PaymentStatus::Refund && ! $request->user()->hasPermission(Permission::PaymentsRefund)) {
            return ApiResponse::error(
                ErrorCode::Forbidden,
                'Refunding a payment requires the refund permission.',
            );
        }

        // Overdue is time-derived and belongs to the scheduler (BR-PAY-06).
        // Allowing it by hand would let someone backdate a collections report.
        if ($target === PaymentStatus::Overdue) {
            return ApiResponse::error(
                ErrorCode::ValidationFailed,
                'Overdue is set automatically once a payment passes its due date.',
            );
        }

        $updated = $this->payments->transition(
            $payment,
            $target,
            $request->user()->id,
            $validated['reason'] ?? null,
        );

        return ApiResponse::success([
            'id' => $updated->id,
            'status' => $updated->status->value,
            'balance' => $updated->sale ? $this->payments->balanceFor($updated->sale) : null,
        ], 'Payment updated.');
    }

    private function authorizeSale(Sale $sale): void
    {
        abort_if($sale->lead === null, 404);

        $this->authorize('view', $sale->lead);
    }

    private function authorizePayment(Payment $payment): void
    {
        abort_if($payment->lead === null, 404);

        $this->authorize('view', $payment->lead);
    }
}
