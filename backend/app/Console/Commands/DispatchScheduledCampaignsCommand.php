<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts campaigns whose scheduled time has arrived (FR-CAMP-02, BR-CAMP-03/05).
 *
 * Scheduling was only half-built. A campaign could be created with a future
 * `scheduled_at` and status `scheduled`, and then nothing ever picked it up:
 * no command, no scheduler entry. It sat there for ever while every screen
 * said it was scheduled - the worst shape a failure can take, because it looks
 * exactly like success until somebody asks why the offer never went out.
 *
 * Scheduler-owned and never user-triggered, for the same reason as missed
 * follow-ups and overdue payments: "its time has come" is the passage of time,
 * and nothing else can notice it happening.
 *
 * The command only *starts* campaigns. Materialising the audience and fanning
 * it out stay in CampaignService and DispatchCampaign, so a scheduled send and
 * a hand-pressed one are the same code path and cannot drift apart (BR-CAMP-03).
 */
class DispatchScheduledCampaignsCommand extends Command
{
    protected $signature = 'crm:dispatch-scheduled-campaigns
                            {--dry-run : Report what would start without starting it}';

    protected $description = 'Start campaigns whose scheduled time has passed';

    public function handle(CampaignService $campaigns): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /*
         * `status = scheduled` is the guard that matters, not the timestamp.
         *
         * `start()` flips the row to Running inside this same tick, so the next
         * sweep no longer sees it however long the due timestamp stays in the
         * past. Selecting on `scheduled_at <= now` alone would re-dispatch
         * every campaign that ever had a schedule, every minute, for ever.
         *
         * It is also what keeps a Paused, Stopped or Completed campaign out:
         * stop is terminal (BR-CAMP-05), and a scheduler that restarted a
         * campaign somebody deliberately stopped would make "stop" a lie.
         */
        $due = Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            // Oldest first, so a backlog after downtime goes out in the order
            // it was meant to.
            ->orderBy('scheduled_at')
            ->get();

        if ($dryRun) {
            $this->info(sprintf('[dry run] %d scheduled campaign(s) would start.', $due->count()));

            return self::SUCCESS;
        }

        $started = 0;

        foreach ($due as $campaign) {
            try {
                $campaigns->start($campaign);
                $started++;
            } catch (Throwable $e) {
                /*
                 * One campaign that cannot start must not take the rest of the
                 * tick with it: the next row is somebody else's send, and a
                 * sweep that dies on the first odd record is a sweep that stops
                 * working the first time anything is odd. Logged rather than
                 * swallowed - a scheduled campaign that failed to start is
                 * exactly the event an operator needs told about.
                 */
                Log::error('Scheduled campaign failed to start', [
                    'campaign_id' => $campaign->id,
                    'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
                    'error' => $e->getMessage(),
                ]);

                $this->warn(sprintf('Campaign #%d could not start: %s', $campaign->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('%d scheduled campaign(s) started.', $started));

        return self::SUCCESS;
    }
}
