<?php

namespace App\Services\Leads;

use App\Enums\ErrorCode;
use App\Enums\LeadStatus;
use App\Enums\StatusSource;
use App\Exceptions\ApiException;
use App\Models\Lead;
use App\Models\LeadProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-product interest (FR-STAT-05, BR-PROD-01..03).
 *
 * The point of this table is independence: a lead can be negotiating the News
 * Portal, warm on the Epaper and explicitly uninterested in News Posting, all
 * at once, and changing any one of those must leave the others exactly as they
 * were (BR-PROD-01/02).
 *
 * Two rules here are easy to get wrong and are enforced rather than assumed:
 *
 * - **Product-level `Not Interested` does not suppress the lead** (BR-PROD-03).
 *   Declining one product is a normal sales outcome; treating it as a
 *   do-not-contact would silently delete the rest of the relationship.
 *
 * - **The lead-level status follows the furthest-advanced product**
 *   (BR-STAT-04), and only ever forwards.
 */
class LeadProductService
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly LeadStatusService $status,
    ) {}

    /**
     * Records interest in a product.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ApiException when the lead already holds this product
     */
    public function attach(Lead $lead, Product $product, array $data = [], ?User $actor = null): LeadProduct
    {
        $existing = LeadProduct::where('lead_id', $lead->id)->where('product_id', $product->id)->first();

        if ($existing !== null) {
            // The DB unique index would refuse this anyway; catching it here
            // returns something the caller can act on - including the id of the
            // row they probably meant to update.
            throw new ApiException(
                ErrorCode::Conflict,
                sprintf('This lead already has interest recorded for %s.', $product->name),
                context: ['lead_product_id' => $existing->id, 'interest_status' => $existing->interest_status->value],
            );
        }

        if (! $product->is_active || $product->trashed()) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf('%s is archived and cannot be added as a new interest.', $product->name),
            );
        }

        $interest = LeadStatus::from($data['interest_status'] ?? LeadStatus::New->value);

        return DB::transaction(function () use ($lead, $product, $data, $actor, $interest) {
            $leadProduct = LeadProduct::create([
                'lead_id' => $lead->id,
                'product_id' => $product->id,
                'interest_status' => $interest,
                'quoted_value' => $data['quoted_value'] ?? null,
                'currency' => $data['currency'] ?? 'INR',
                'notes' => $data['notes'] ?? null,
                'first_interest_at' => $this->marksRealInterest($interest) ? now() : null,
                'last_activity_at' => now(),
            ]);

            $this->leads->recordActivity(
                $lead,
                $actor?->id,
                'product_interest_added',
                sprintf('Interested in %s', $product->name),
                ['product_id' => $product->id, 'interest_status' => $interest->value],
            );

            $this->syncLeadStatus($lead, $actor);

            return $leadProduct->fresh();
        });
    }

    /**
     * Moves one product's interest status.
     *
     * Runs the SAME transition matrix as the lead-level status: a product
     * cannot jump from New to Negotiation any more than a lead can, and the
     * rules a telecaller learns in one place should hold in the other.
     *
     * @throws ApiException on an illegal transition
     */
    public function changeInterest(
        LeadProduct $leadProduct,
        LeadStatus $target,
        ?User $actor = null,
        ?string $note = null,
    ): LeadProduct {
        $current = $leadProduct->interest_status;

        if ($current === $target) {
            throw new ApiException(
                ErrorCode::LeadInvalidStatusTransition,
                sprintf('This product is already %s.', $target->label()),
                context: ['current' => $current->value],
            );
        }

        if (! $current->canTransitionTo($target)) {
            throw new ApiException(
                ErrorCode::LeadInvalidStatusTransition,
                sprintf('Product interest cannot move from %s to %s.', $current->label(), $target->label()),
                context: [
                    'from' => $current->value,
                    'to' => $target->value,
                    'allowed' => array_map(
                        fn (LeadStatus $status) => $status->value,
                        $current->allowedTransitions(),
                    ),
                ],
            );
        }

        return DB::transaction(function () use ($leadProduct, $current, $target, $actor, $note) {
            $leadProduct->forceFill([
                'interest_status' => $target,
                'last_activity_at' => now(),
                'first_interest_at' => $leadProduct->first_interest_at
                    ?? ($this->marksRealInterest($target) ? now() : null),
            ])->save();

            $lead = $leadProduct->lead;

            $this->leads->recordActivity(
                $lead,
                $actor?->id,
                'product_interest_changed',
                sprintf(
                    '%s: %s → %s',
                    $leadProduct->product->name,
                    $current->label(),
                    $target->label(),
                ),
                [
                    'product_id' => $leadProduct->product_id,
                    'from' => $current->value,
                    'to' => $target->value,
                    'note' => $note,
                ],
            );

            /*
             * BR-PROD-03: declining ONE product never writes lead-level DNC.
             * The lead is still a live relationship for everything else, and
             * suppressing them here would end it on the strength of a single
             * "no thanks". Lead-level DNC needs lead-level Not Interested.
             */

            $this->syncLeadStatus($lead, $actor);

            return $leadProduct->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDetails(LeadProduct $leadProduct, array $data, ?User $actor = null): LeadProduct
    {
        $leadProduct->update(array_intersect_key($data, array_flip([
            'quoted_value', 'currency', 'notes',
        ])) + ['last_activity_at' => now()]);

        return $leadProduct->fresh();
    }

    /**
     * Removes a product interest.
     *
     * A hard delete, unlike almost everything else here: an interest added by
     * mistake is a data-entry error, not history worth keeping. Deliberate
     * outcomes are recorded by moving the interest to `Not Interested` or
     * `Lost`, which is what the timeline entry below nudges towards.
     */
    public function detach(LeadProduct $leadProduct, ?User $actor = null): void
    {
        $lead = $leadProduct->lead;
        $productName = $leadProduct->product->name;

        DB::transaction(function () use ($leadProduct, $lead, $productName, $actor) {
            $leadProduct->delete();

            $this->leads->recordActivity(
                $lead,
                $actor?->id,
                'product_interest_removed',
                sprintf('Removed interest in %s', $productName),
            );
        });
    }

    // -----------------------------------------------------------------------
    // BR-STAT-04
    // -----------------------------------------------------------------------

    /**
     * Advances the lead to match its furthest-advanced product.
     *
     * BR-STAT-04 says the lead-level status *is* the furthest-advanced state
     * across its products, while BR-STAT-02 and BR-STAT-05 describe a status a
     * person sets directly and a matrix that governs it. Those cannot both be
     * literally true, so this is the reading in force: **the lead status is
     * authoritative and set directly; product interest can pull it forward,
     * never push it back.**
     *
     * Two consequences, both deliberate:
     * - A lead with no products keeps a perfectly valid status. A derived-only
     *   status would leave every imported lead stateless.
     * - A closed lead is never dragged back open by a product edit; the
     *   transition matrix refuses it, and the refusal is the correct answer.
     *
     * Recorded as a system change with a null actor: nobody chose this, so
     * crediting a person for it would corrupt attribution.
     */
    private function syncLeadStatus(Lead $lead, ?User $actor = null): void
    {
        $furthest = LeadProduct::where('lead_id', $lead->id)
            ->get()
            ->map(fn (LeadProduct $row) => $row->interest_status)
            ->sortByDesc(fn (LeadStatus $status) => $status->pipelineRank())
            ->first();

        // A closed lead is never dragged back open by a product edit. Checked
        // here as well as in the reopen guard: Lost and Not Interested rank
        // below every open status, so without this every interested product
        // would look like an advance.
        if ($lead->status->isClosed()) {
            return;
        }

        if ($furthest === null || $furthest->pipelineRank() <= $lead->status->pipelineRank()) {
            return;
        }

        // Never auto-converts: Converted needs a sale behind it (BR-PAY-05),
        // and it is terminal, so an automatic one could not be undone.
        if ($furthest === LeadStatus::Converted) {
            return;
        }

        try {
            $this->status->change(
                $lead,
                $furthest,
                actor: null,
                source: StatusSource::System,
                reason: 'Advanced automatically to match product interest (BR-STAT-04).',
            );
        } catch (ApiException $e) {
            // An illegal advance is not an error the caller did anything about
            // - the product change itself was valid and has been saved. Logged
            // rather than raised, so a closed lead does not fail a legitimate
            // product edit.
            Log::info('Lead status not advanced from product interest.', [
                'lead_id' => $lead->id,
                'from' => $lead->status->value,
                'to' => $furthest->value,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** Interest that means something more than "on the list". */
    private function marksRealInterest(LeadStatus $status): bool
    {
        return $status->pipelineRank() >= LeadStatus::Interested->pipelineRank();
    }
}
