<?php

namespace App\Jobs;

use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Exceptions\ApiException;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignEligibility;
use App\Services\Campaigns\CampaignService;
use App\Services\Messaging\OutboundMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One campaign message to one lead (BR-CAMP-01/02, BR-DNC-03/05).
 *
 * **Eligibility is re-evaluated here**, not trusted from when the audience was
 * built. That is BR-CAMP-02, and the case it exists for is ordinary: a lead
 * opts out at 09:20 for a campaign whose audience was resolved at 09:00 and
 * which is still working through 12,000 recipients at 09:40.
 *
 * Every outcome is recorded on the recipient row. A targeted lead either gets a
 * message or gets a reason - "nothing happened" is not an outcome anybody can
 * report on (BR-DNC-05).
 */
class SendCampaignMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $recipientId) {}

    public function handle(CampaignEligibility $eligibility, OutboundMessageService $messages): void
    {
        /*
         * The lead is loaded WITH trashed rows on purpose.
         *
         * The audience is materialised once and never rebuilt, so a lead
         * soft-deleted afterwards still has a recipient row pointing at it. The
         * default scope hid that lead, this job could not resolve it, and it
         * returned - leaving the row `pending` for ever and the campaign one
         * outstanding recipient short of completion, permanently. Loading the
         * archived lead is what lets the skip below name the real reason
         * instead of silently giving up on the row.
         */
        $recipient = CampaignRecipient::with([
            'campaign',
            'lead' => fn ($query) => $query->withTrashed(),
        ])->find($this->recipientId);

        if ($recipient === null || $recipient->status !== 'pending') {
            return;
        }

        $campaign = $recipient->campaign;
        $lead = $recipient->lead;

        // No campaign behind the row leaves nothing to record an outcome
        // against, and nothing to complete. Genuinely nothing to do.
        if ($campaign === null) {
            return;
        }

        // Paused or stopped since the fan-out. Recorded as a skip with its own
        // reason rather than left pending, so the counts still add up.
        if (! $campaign->status->isDispatchable()) {
            $this->skip($recipient, CampaignSkipReason::CampaignNotRunning);

            return;
        }

        /*
         * BR-CAMP-01 names "the lead is not archived" as an eligibility
         * condition, and this is the moment it can be judged - the audience
         * query could not, because a lead archived at 09:20 was still present
         * when the audience was resolved at 09:00 (BR-CAMP-02).
         *
         * A hard-deleted lead lands here as null and takes the same branch. The
         * operational fact is identical - the row points at somebody who is not
         * there to receive anything - and BR-DNC-05 wants a reason recorded
         * either way rather than a row nobody can account for.
         */
        if ($lead === null || $lead->trashed()) {
            $this->skip($recipient, CampaignSkipReason::LeadArchived);

            return;
        }

        $reason = $eligibility->reasonToSkip($lead, $campaign->channel);

        if ($reason !== null) {
            $this->skip($recipient, $reason);

            return;
        }

        try {
            /*
             * Through the same service an individual send uses. It asks the DNC
             * gate again itself, and the job behind it asks a third time at
             * provider hand-off - none of which is redundant, because each runs
             * at a different moment (BR-DNC-03).
             */
            $message = $messages->queue(
                $lead,
                $campaign->channel,
                content: [],
                actorId: $campaign->created_by,
                template: $campaign->template,
                campaignId: $campaign->id,
            );
        } catch (ApiException) {
            // The only thing queue() throws for is an unusable address, which
            // eligibility already checks - so reaching here means the lead's
            // details changed in between. Still a skip, not a failure.
            $this->skip($recipient, CampaignSkipReason::NoContactDetail);

            return;
        }

        $recipient->update([
            'status' => $message->status === 'skipped' ? 'skipped' : 'sent',
            'skip_reason' => $message->status === 'skipped' ? CampaignSkipReason::Suppressed->value : null,
            'message_id' => $message->id,
            'processed_at' => now(),
        ]);

        $campaign->increment($message->status === 'skipped' ? 'total_skipped' : 'total_sent');

        $this->completeIfFinished($campaign);
    }

    /**
     * Attempts exhausted. The row must not be left `pending`.
     *
     * Completion is decided by "no recipient row is still pending", so a job
     * that gives up without recording an outcome holds its campaign Running for
     * ever: never completing, never notifying its owner, never leaving the
     * in-flight list. The same reason `ImportLeadRow::failed()` exists - and the
     * same rule, BR-DNC-05, that forbids "nothing happened" as an outcome.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Campaign message job failed permanently.', [
            'campaign_recipient_id' => $this->recipientId,
            'exception' => $e->getMessage(),
        ]);

        /*
         * Conditional on `pending` in one statement: the throw may have come
         * after the row was already resolved (the counter increments sit after
         * the update), and re-marking it would both lose the real outcome and
         * count the recipient twice.
         */
        $claimed = CampaignRecipient::where('id', $this->recipientId)
            ->where('status', 'pending')
            ->update(['status' => 'failed', 'processed_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $campaign = CampaignRecipient::find($this->recipientId)?->campaign;

        if ($campaign === null) {
            return;
        }

        // `total_failed` is what the campaign report reads for failure_rate;
        // until this handler existed nothing ever wrote to it.
        $campaign->increment('total_failed');

        $this->completeIfFinished($campaign);
    }

    private function skip(CampaignRecipient $recipient, CampaignSkipReason $reason): void
    {
        $recipient->update([
            'status' => 'skipped',
            'skip_reason' => $reason->value,
            'processed_at' => now(),
        ]);

        $recipient->campaign?->increment('total_skipped');

        $this->completeIfFinished($recipient->campaign);
    }

    /**
     * Marks the campaign complete once the last recipient is done.
     *
     * Decided by whichever job finishes last rather than announced up front,
     * because the fan-out does not know how long the sends will take. A row
     * marked `failed` counts as done here exactly like a send or a skip - that
     * is what lets a dead job's campaign still reach a terminal state.
     */
    private function completeIfFinished(?Campaign $campaign): void
    {
        if ($campaign === null || $campaign->status !== CampaignStatus::Running) {
            return;
        }

        $outstanding = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->exists();

        if (! $outstanding) {
            $campaign->forceFill([
                'status' => CampaignStatus::Completed->value,
                'completed_at' => now(),
            ])->save();

            app(CampaignService::class)->notifyOwnerOfCompletion($campaign);
        }
    }
}
