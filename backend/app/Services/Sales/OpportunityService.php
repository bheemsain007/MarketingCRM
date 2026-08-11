<?php

namespace App\Services\Sales;

use App\Enums\ErrorCode;
use App\Enums\LostReason;
use App\Enums\OpportunityStatus;
use App\Exceptions\ApiException;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Product;
use App\Services\Leads\LeadService;
use Illuminate\Support\Facades\DB;

/**
 * Opportunities (FR-SALE-01/02/05, BR-SALE-01/04, BR-CUST-03).
 *
 * The opportunity is where deal value lives. The lead's STATUS is where the
 * pipeline stage lives - the two are deliberately not duplicated, because two
 * sources of truth for "where is this deal?" disagree the first time somebody
 * updates one and not the other.
 */
class OpportunityService
{
    public function __construct(private readonly LeadService $leads) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Lead $lead, array $data, ?int $actorId = null): Opportunity
    {
        return DB::transaction(function () use ($lead, $data, $actorId) {
            $opportunity = Opportunity::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'lead_id' => $lead->id,
                // BR-CUST-03: repeat business is a new opportunity under the
                // EXISTING customer, so the link is carried from the start
                // rather than recreated at sale time.
                'customer_id' => $data['customer_id'] ?? $this->existingCustomerIdFor($lead),
                'title' => $data['title'],
                'status' => OpportunityStatus::Open->value,
                'value' => 0,
                'currency' => $data['currency'] ?? 'INR',
                'expected_close_on' => $data['expected_close_on'] ?? null,
                'owner_id' => $data['owner_id'] ?? $lead->assigned_to ?? $actorId,
                'created_by' => $actorId,
            ]);

            foreach ($data['products'] ?? [] as $line) {
                $this->addProduct($opportunity, (int) $line['product_id'], $line, recalculate: false);
            }

            $this->recalculate($opportunity);

            $this->leads->recordActivity(
                $lead,
                $actorId,
                'opportunity_created',
                'Opportunity opened: '.$opportunity->title,
                ['opportunity_id' => $opportunity->id],
            );

            return $opportunity->fresh(['products']);
        });
    }

    /**
     * Adds or updates one product line.
     *
     * `unit_price` defaults to the product's list price but is stored on the
     * line. A price rise next quarter must not silently reprice every open
     * deal that quoted the old figure.
     *
     * @param  array<string, mixed>  $line
     */
    public function addProduct(Opportunity $opportunity, int $productId, array $line = [], bool $recalculate = true): Opportunity
    {
        $this->guardOpen($opportunity);

        $product = Product::findOrFail($productId);
        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $unitPrice = (float) ($line['unit_price'] ?? $product->base_price ?? 0);

        $opportunity->products()->updateOrCreate(
            ['product_id' => $productId],
            [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * $quantity, 2),
            ],
        );

        return $recalculate ? $this->recalculate($opportunity) : $opportunity;
    }

    public function removeProduct(Opportunity $opportunity, int $productId): Opportunity
    {
        $this->guardOpen($opportunity);

        $opportunity->products()->where('product_id', $productId)->delete();

        return $this->recalculate($opportunity);
    }

    /** Value is the sum of its lines - never set directly by a caller. */
    public function recalculate(Opportunity $opportunity): Opportunity
    {
        $opportunity->update([
            'value' => $opportunity->products()->sum('line_total'),
        ]);

        return $opportunity->fresh(['products']);
    }

    /**
     * Marks a deal lost (FR-SALE-05, BR-SALE-04).
     *
     * The reason is a required enum. Free text produces "price", "Price" and
     * "too expensive" as three answers to one question, and the report that
     * was meant to show why deals are lost shows nothing.
     */
    public function markLost(Opportunity $opportunity, LostReason $reason, ?string $notes = null, ?int $actorId = null): Opportunity
    {
        $this->guardOpen($opportunity);

        if ($reason === LostReason::Other && ($notes === null || trim($notes) === '')) {
            // "Other" with no explanation is the same as no reason at all, and
            // it is the option people reach for when the list is inconvenient.
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Choosing "Other" requires a note explaining why the deal was lost.',
                errors: [[
                    'field' => 'lost_notes',
                    'code' => ErrorCode::ValidationFailed->value,
                    'message' => 'Explain why the deal was lost.',
                ]],
            );
        }

        return DB::transaction(function () use ($opportunity, $reason, $notes, $actorId) {
            $opportunity->update([
                'status' => OpportunityStatus::Lost->value,
                'lost_reason' => $reason->value,
                'lost_notes' => $notes,
                'closed_at' => now(),
            ]);

            if ($opportunity->lead) {
                $this->leads->recordActivity(
                    $opportunity->lead,
                    $actorId,
                    'opportunity_lost',
                    'Opportunity lost: '.$reason->label(),
                    ['opportunity_id' => $opportunity->id, 'reason' => $reason->value],
                );
            }

            return $opportunity->fresh();
        });
    }

    /**
     * BR-SALE-01: reaching `Proposal` requires an opportunity with at least one
     * product and a value.
     *
     * Exposed for `LeadStatusService` to ask, rather than that service reaching
     * into the sales tables itself.
     */
    public function hasProposableOpportunity(Lead $lead): bool
    {
        return Opportunity::query()
            ->where('lead_id', $lead->id)
            ->where('status', OpportunityStatus::Open->value)
            ->where('value', '>', 0)
            ->whereHas('products')
            ->exists();
    }

    /** @throws ApiException when the deal is already closed */
    private function guardOpen(Opportunity $opportunity): void
    {
        if ($opportunity->status->isClosed()) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf('This opportunity is already %s.', $opportunity->status->label()),
            );
        }
    }

    /** The customer this lead already belongs to, if any (BR-CUST-03). */
    private function existingCustomerIdFor(Lead $lead): ?int
    {
        return DB::table('customer_leads')->where('lead_id', $lead->id)->value('customer_id');
    }
}
