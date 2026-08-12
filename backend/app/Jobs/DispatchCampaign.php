<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fans a campaign out into one job per recipient (FR-CAMP-05, BR-CAMP-03).
 *
 * The request that starts a campaign only queues THIS. Everything else happens
 * on the queue, so a campaign of any size completes without an HTTP timeout.
 *
 * One job per recipient rather than one loop over all of them: a provider
 * failure on lead 4,000 must not cost the 6,000 sends behind it, and retries
 * then apply to the one message that failed instead of the whole run.
 */
class DispatchCampaign implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $campaignId) {}

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);

        // Paused or stopped between the request and this job running. Checked
        // again inside each recipient job, because this fan-out may take a
        // while on a large audience (BR-CAMP-05).
        if ($campaign === null || ! $campaign->status->isDispatchable()) {
            return;
        }

        $queued = 0;

        CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->chunkById(500, function ($recipients) use (&$queued) {
                foreach ($recipients as $recipient) {
                    SendCampaignMessage::dispatch($recipient->id)->onQueue('messages');
                    $queued++;
                }
            });

        $campaign->forceFill([
            'total_queued' => $campaign->total_queued + $queued,
        ])->save();

        /*
         * Nothing left to hand out. The campaign is not Completed yet - the
         * recipient jobs are still running - so completion is decided by the
         * last one to finish rather than announced here.
         */
        if ($queued === 0) {
            $this->completeIfFinished($campaign);
        }
    }

    private function completeIfFinished(Campaign $campaign): void
    {
        $outstanding = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->exists();

        if (! $outstanding && $campaign->status === CampaignStatus::Running) {
            $campaign->forceFill([
                'status' => CampaignStatus::Completed->value,
                'completed_at' => now(),
            ])->save();
        }
    }
}
