<?php

namespace App\Services\Leads;

use App\Enums\DncReason;
use App\Enums\ErrorCode;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Enums\StatusSource;
use App\Exceptions\ApiException;
use App\Models\Lead;
use App\Models\LeadStatusHistory;
use App\Models\User;
use App\Services\Dnc\DncService;
use App\Services\Payments\PaymentService;
use App\Services\Sales\SaleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Lead status transitions (FR-STAT-01..03, BR-STAT-01..05).
 *
 * The matrix itself lives in the `LeadStatus` enum so there is one definition
 * of what is legal; this service is what enforces it, and it is the ONLY code
 * permitted to write `leads.status`. The column is deliberately outside mass
 * assignment (SEC-IN-06) - a status that could be set by a PATCH body would
 * make the matrix decorative.
 *
 * Three things happen on every accepted change, in one transaction: the lead
 * moves, an append-only history row is written (BR-STAT-03), and the timeline
 * gets an entry (FR-LEAD-09). A status change that logged nothing would leave
 * a disputed conversion unanswerable.
 */
class LeadStatusService
{
    public function __construct(
        private readonly LeadService $leads,
        private readonly DncService $dnc,
        private readonly SaleService $sales,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Moves a lead to a new status, or explains why it cannot.
     *
     * A null `$actor` means the system did it - the Interest Engine, the
     * scheduler, a webhook. System changes bypass the authority checks (there
     * is no user to check) and are recorded with a null actor rather than
     * being credited to whoever happened to trigger them, which would corrupt
     * attribution (GLOSSARY §2.6).
     *
     * @throws ApiException on an illegal transition, missing authority or a
     *                      missing reason
     */
    public function change(
        Lead $lead,
        LeadStatus $target,
        ?User $actor = null,
        StatusSource $source = StatusSource::Manual,
        ?string $reason = null,
        ?Model $reference = null,
    ): Lead {
        $current = $lead->status;

        $this->guardArchived($lead);
        $this->guardTransition($current, $target);
        $this->guardConverted($target, $lead);
        $this->guardReopen($current, $actor, $reason);

        return DB::transaction(function () use ($lead, $current, $target, $actor, $source, $reason, $reference) {
            // forceFill, because `status` is guarded against mass assignment -
            // this service is the exception, not a loophole.
            $lead->forceFill(['status' => $target, 'updated_by' => $actor?->id])->save();

            LeadStatusHistory::create([
                'lead_id' => $lead->id,
                'from_status' => $current,
                'to_status' => $target,
                'changed_by' => $actor?->id,
                'source_channel' => $source->value,
                'reason' => $reason,
                'reference_type' => $reference !== null ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
            ]);

            $this->leads->recordActivity(
                $lead,
                $actor?->id,
                'status_changed',
                sprintf('Status changed from %s to %s', $current->label(), $target->label()),
                [
                    'from' => $current->value,
                    'to' => $target->value,
                    'source' => $source->value,
                    'reason' => $reason,
                ],
            );

            /*
             * BR-DNC-07: `Not Interested` writes suppression automatically.
             * Requiring a second manual step would mean the CRM knows the lead
             * said no while continuing to include them in campaigns until
             * somebody remembers - which is the failure this rule exists to
             * prevent.
             */
            if ($target->triggersSuppression()) {
                $this->dnc->suppress(
                    $lead,
                    DncReason::NotInterested,
                    channel: null,          // Not Interested blocks everything.
                    source: 'system',
                    actorId: $actor?->id,
                    note: $reason,
                );
            }

            /*
             * Reopening does NOT clear suppression (BR-STAT-02, BR-DNC-06).
             * Someone who said "do not contact me" has not changed their mind
             * because an internal status moved; un-suppressing is a separate,
             * separately-audited action requiring dnc.remove.
             */

            return $lead->fresh();
        });
    }

    /**
     * True when this actor could make this move - for rendering a UI without
     * discovering the answer through a 422.
     */
    public function canChange(Lead $lead, LeadStatus $target, ?User $actor = null): bool
    {
        try {
            $this->guardArchived($lead);
            $this->guardTransition($lead->status, $target);
            $this->guardConverted($target, $lead);
            // A reason is supplied at call time, so it is not knowable here;
            // authority is.
            $this->guardReopen($lead->status, $actor, 'probe');
        } catch (ApiException) {
            return false;
        }

        return true;
    }

    /**
     * Targets this actor may currently move the lead to.
     *
     * @return array<int, LeadStatus>
     */
    public function availableTransitions(Lead $lead, ?User $actor = null): array
    {
        return array_values(array_filter(
            $lead->status->allowedTransitions(),
            fn (LeadStatus $target) => $this->canChange($lead, $target, $actor),
        ));
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    private function guardArchived(Lead $lead): void
    {
        if ($lead->trashed()) {
            throw new ApiException(ErrorCode::LeadArchived);
        }
    }

    private function guardTransition(LeadStatus $current, LeadStatus $target): void
    {
        if ($current === $target) {
            throw new ApiException(
                ErrorCode::LeadInvalidStatusTransition,
                sprintf('The lead is already %s.', $target->label()),
                context: ['current' => $current->value],
            );
        }

        if ($current->canTransitionTo($target)) {
            return;
        }

        throw new ApiException(
            ErrorCode::LeadInvalidStatusTransition,
            sprintf('A lead cannot move from %s to %s.', $current->label(), $target->label()),
            context: [
                'from' => $current->value,
                'to' => $target->value,
                // The legal moves come back with the refusal so a client can
                // correct itself rather than guessing.
                'allowed' => array_map(
                    fn (LeadStatus $status) => $status->value,
                    $current->allowedTransitions(),
                ),
                'is_terminal' => $current->isTerminal(),
            ],
        );
    }

    /**
     * `Converted` requires a linked Sale AND money against it
     * (BR-STAT-05, BR-PAY-05).
     *
     * Both halves, since Phase 23. A sale alone is a promise; a sale with a
     * `Partial` or `Paid` payment is a conversion. Without the second half, a
     * deal recorded optimistically inflates conversion reporting and - once
     * attribution lands - telecaller pay.
     *
     * Still not a status anyone may simply set: `Converted` is terminal and the
     * matrix offers no way back out, so a lead marked converted in error cannot
     * be corrected.
     */
    private function guardConverted(LeadStatus $target, ?Lead $lead = null): void
    {
        if ($target !== LeadStatus::Converted) {
            return;
        }

        if ($lead !== null && $this->sales->leadHasSale($lead)) {
            if ($this->payments->saleHasQualifyingPayment($lead->id)) {
                return;
            }

            throw new ApiException(
                ErrorCode::LeadInvalidStatusTransition,
                'This lead has a sale but no payment received against it. A lead converts once a payment is partial or paid.',
                context: ['to' => LeadStatus::Converted->value, 'requires' => 'payment'],
            );
        }

        throw new ApiException(
            ErrorCode::LeadInvalidStatusTransition,
            'A lead is converted by recording a sale against an opportunity, not by setting the status directly.',
            context: ['to' => LeadStatus::Converted->value, 'requires' => 'sale'],
        );
    }

    /**
     * Reopens (from Lost or Not Interested) need Manager+ AND a written reason
     * (BR-STAT-02, BR-STAT-05).
     *
     * Both halves matter. Without the permission, a telecaller can quietly
     * recycle their own dead leads to flatter their numbers; without the
     * reason, the audit trail records that it happened but not why - and "why"
     * is the entire question when a reopened lead later converts.
     */
    private function guardReopen(LeadStatus $current, ?User $actor, ?string $reason): void
    {
        if (! $current->isReopenFrom()) {
            return;
        }

        /*
         * Nothing automatic reopens a closed lead.
         *
         * A null actor means the system did it, and an earlier version of this
         * guard skipped the permission check in that case - "there is no user
         * to check". That was a hole: product-interest propagation (BR-STAT-04)
         * calls this with a null actor, so recording interest against a Lost
         * lead silently reopened it, with no manager and no real reason. Both
         * halves of the rule exist to make reopening a deliberate human act,
         * and an automatic caller satisfies neither.
         */
        if ($actor === null) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'A closed lead can only be reopened by a manager, never automatically.',
            );
        }

        if (! $actor->hasPermission(Permission::LeadsReopen)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Reopening a closed lead requires a manager.',
            );
        }

        if (trim((string) $reason) === '') {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'A reason is required when reopening a closed lead.',
                errors: [[
                    'field' => 'reason',
                    'code' => ErrorCode::ValidationFailed->value,
                    'message' => 'Explain why this lead is being reopened.',
                ]],
            );
        }
    }
}
