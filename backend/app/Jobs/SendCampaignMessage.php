<?php

namespace App\Jobs;

use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Exceptions\ApiException;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignEligibility;
use App\Services\Messaging\OutboundMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        $recipient = CampaignRecipient::with(['campaign', 'lead'])->find($this->recipientId);

        if ($recipient === null || $recipient->status !== 'pending') {
            return;
        }

        $campaign = $recipient->campaign;
        $lead = $recipient->lead;

        if ($campaign === null || $lead === null) {
            return;
        }

        // Paused or stopped since the fan-out. Recorded as a skip with its own
        // reason rather than left pending, so the counts still add up.
        if (! $campaign->status->isDispatchable()) {
            $this->skip($recipient, CampaignSkipReason::CampaignNotRunning);

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

        $this->completeIfFinished($recipient);
    }

    private function skip(CampaignRecipient $recipient, CampaignSkipReason $reason): void
    {
        $recipient->update([
            'status' => 'skipped',
            'skip_reason' => $reason->value,
            'processed_at' => now(),
        ]);

        $recipient->campaign?->increment('total_skipped');

        $this->completeIfFinished($recipient);
    }

    /**
     * Marks the campaign complete once the last recipient is done.
     *
     * Decided by whichever job finishes last rather than announced up front,
     * because the fan-out does not know how long the sends will take.
     */
    private function completeIfFinished(CampaignRecipient $recipient): void
    {
        $campaign = $recipient->campaign;

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
        }
    }
}
