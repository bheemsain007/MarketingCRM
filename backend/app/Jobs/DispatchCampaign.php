<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * The fan-out died. Nothing will ever hand these rows out again.
     *
     * `tries = 1`, so there is no later attempt to finish the chunk loop: every
     * recipient still `pending` is one no send job will ever be dispatched for,
     * and completion is decided by "no row is still pending". Left alone the
     * campaign stays Running for ever, which reads on every screen as a send
     * still in progress (BR-CAMP-05, BR-DNC-05).
     */
    public function failed(Throwable $e): void
    {
        Log::error('Campaign fan-out job failed permanently.', [
            'campaign_id' => $this->campaignId,
            'exception' => $e->getMessage(),
        ]);

        $campaign = Campaign::find($this->campaignId);

        if ($campaign === null || $campaign->status !== CampaignStatus::Running) {
            return;
        }

        /*
         * A failure part-way through the chunk loop leaves some jobs already on
         * the queue, and marking their rows here costs those sends - the send
         * job only acts on a `pending` row. That is the deliberate trade: a
         * half-dispatched campaign cannot be told apart from a stuck one, and
         * an audience frozen at `pending` for ever is strictly worse than one
         * recorded as failed, which an operator can see and clone to re-run.
         */
        $abandoned = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->update(['status' => 'failed', 'processed_at' => now()]);

        if ($abandoned > 0) {
            $campaign->increment('total_failed', $abandoned);
        }

        // Now reachable: with no pending rows left the campaign becomes
        // terminal, and `notifyOwnerOfCompletion` reads zero sends against a
        // real audience as the failure it is.
        $this->completeIfFinished($campaign);
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

            app(CampaignService::class)->notifyOwnerOfCompletion($campaign);
        }
    }
}
