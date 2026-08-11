<?php

namespace App\Services\Sales;

use App\Enums\ErrorCode;
use App\Enums\Permission;
use App\Enums\QuotationStatus;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Quotations and discount approval (FR-SALE-03/04, BR-SALE-03).
 *
 * The discount threshold is configuration (`crm.discount_approval_threshold`,
 * proposed 15% - T-21), so changing it is not a deployment.
 */
class QuotationService
{
    /**
     * Drafts a quotation from the opportunity's current lines.
     *
     * Items are COPIED. A quotation is a number a customer has been given; if
     * it recalculated itself from the opportunity, editing the deal afterwards
     * would silently change what we are on record as having offered.
     *
     * @param  array<string, mixed>  $data
     */
    public function draft(Opportunity $opportunity, array $data = [], ?int $actorId = null): Quotation
    {
        if ($opportunity->products()->count() === 0) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Add at least one product to the opportunity before quoting.',
            );
        }

        $discountPercent = round((float) ($data['discount_percent'] ?? 0), 2);

        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new ApiException(ErrorCode::ValidationFailed, 'The discount must be between 0 and 100 percent.');
        }

        return DB::transaction(function () use ($opportunity, $data, $discountPercent, $actorId) {
            $subtotal = (float) $opportunity->products()->sum('line_total');
            $discountAmount = round($subtotal * $discountPercent / 100, 2);

            $quotation = Quotation::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'opportunity_id' => $opportunity->id,
                'number' => $this->nextNumber(),
                // Above the threshold it cannot be issued without a decision;
                // at or below it, it is ready to go (BR-SALE-03).
                'status' => $this->needsApproval($discountPercent)
                    ? QuotationStatus::PendingApproval->value
                    : QuotationStatus::Draft->value,
                'subtotal' => $subtotal,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'total' => round($subtotal - $discountAmount, 2),
                'currency' => $opportunity->currency,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach ($opportunity->products()->with('product')->get() as $line) {
                $quotation->items()->create([
                    'product_id' => $line->product_id,
                    'description' => $line->product?->name ?? 'Product',
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                ]);
            }

            return $quotation->fresh(['items']);
        });
    }

    /**
     * BR-SALE-03: approval is Manager+ and is audited.
     *
     * The approver must not be the person who raised it. A salesperson who can
     * approve their own discount does not have an approval step, they have a
     * checkbox.
     */
    public function approve(Quotation $quotation, User $approver): Quotation
    {
        $this->guardApprovable($quotation);

        if (! $approver->hasPermission(Permission::DiscountsApprove)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Approving a discount above the threshold requires Manager authority.',
            );
        }

        if ($quotation->created_by !== null && $quotation->created_by === $approver->id) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'A discount cannot be approved by the person who raised it.',
            );
        }

        return DB::transaction(function () use ($quotation, $approver) {
            $quotation->update([
                'status' => QuotationStatus::Approved->value,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);

            $this->audit($quotation, $approver, 'quotation_discount_approved');

            return $quotation->fresh();
        });
    }

    public function reject(Quotation $quotation, User $approver, string $reason): Quotation
    {
        $this->guardApprovable($quotation);

        if (! $approver->hasPermission(Permission::DiscountsApprove)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Rejecting a discount requires Manager authority.',
            );
        }

        return DB::transaction(function () use ($quotation, $approver, $reason) {
            $quotation->update([
                'status' => QuotationStatus::Rejected->value,
                'rejection_reason' => $reason,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $this->audit($quotation, $approver, 'quotation_discount_rejected');

            return $quotation->fresh();
        });
    }

    /**
     * Issues the quotation to the customer.
     *
     * After this it is immutable - the figure exists on somebody else's desk.
     */
    public function issue(Quotation $quotation): Quotation
    {
        if (! $quotation->status->canBeIssued()) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                $quotation->status === QuotationStatus::PendingApproval
                    ? 'This discount needs approval before the quotation can be issued.'
                    : sprintf('A %s quotation cannot be issued.', strtolower($quotation->status->label())),
            );
        }

        $quotation->update(['status' => QuotationStatus::Issued->value]);

        return $quotation->fresh();
    }

    public function needsApproval(float $discountPercent): bool
    {
        return $discountPercent > (float) config('crm.discount_approval_threshold', 15);
    }

    private function guardApprovable(Quotation $quotation): void
    {
        if ($quotation->status !== QuotationStatus::PendingApproval) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'This quotation is not awaiting approval.',
            );
        }
    }

    /**
     * A number a customer can quote back at us. Sequential per year so it is
     * readable, with the uniqueness guaranteed by the database rather than by
     * this method winning a race.
     */
    private function nextNumber(): string
    {
        $prefix = 'Q-'.now()->format('Y').'-';

        $last = Quotation::withTrashed()
            ->where('tenant_id', config('crm.default_tenant_id'))
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $next = $last !== null ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function audit(Quotation $quotation, User $actor, string $action): void
    {
        // `discounts.approve` is already in Permission::isAudited(), so the
        // middleware records the access. This records the DECISION - which
        // quotation, at what discount - which the access log cannot.
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'description' => $quotation->number,
            'new_values' => [
                'quotation_id' => $quotation->id,
                'discount_percent' => (string) $quotation->discount_percent,
                'total' => (string) $quotation->total,
            ],
        ]);
    }
}
