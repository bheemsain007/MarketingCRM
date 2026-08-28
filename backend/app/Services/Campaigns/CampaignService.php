<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\ErrorCode;
use App\Enums\LeadStatus;
use App\Exceptions\ApiException;
use App\Jobs\DispatchCampaign;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\User;
use App\Services\Messaging\MessageDriverManager;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campaign lifecycle and audience resolution (FR-CAMP-01/02/05, BR-CAMP-01..05).
 *
 * Nothing here sends anything. Building the audience and handing it to the
 * queue is the whole job (FR-CAMP-05) - a campaign of any size has to complete
 * without an HTTP timeout, so the request only ever starts the work.
 *
 * Eligibility is deliberately NOT decided here either. It is decided per
 * recipient at dispatch time, because an audience resolved at 09:00 and sent at
 * 09:40 is a set of assumptions that have had forty minutes to go stale
 * (BR-CAMP-02).
 */
class CampaignService
{
    public function __construct(
        private readonly CampaignEligibility $eligibility,
        private readonly NotificationService $notifications,
        private readonly MessageDriverManager $drivers,
    ) {}

    /**
     * The caveat an operator has to see before believing a campaign worked.
     *
     * An unconfigured channel falls back to `LogDriver`: the message is recorded
     * as sent and nobody receives anything. `MessageController::store()` already
     * says so on its 202 for a single send - a campaign runs the same fallback
     * across the whole audience, where the same silence is fifty thousand times
     * more expensive and the counters read as a completely successful send
     * (FR-COMM-05).
     */
    public function deliveryCaveat(Campaign $campaign): ?string
    {
        if ($this->drivers->isLive($campaign->channel)) {
            return null;
        }

        return sprintf(
            'No provider is configured for %s yet, so messages are recorded but not delivered.',
            $campaign->channel->label(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Campaign
    {
        return Campaign::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'channel' => $data['channel'],
            'template_id' => $data['template_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'audience_filters' => $data['audience_filters'] ?? [],
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'status' => isset($data['scheduled_at'])
                ? CampaignStatus::Scheduled->value
                : CampaignStatus::Draft->value,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Campaign $campaign, array $data, ?User $actor = null): Campaign
    {
        /*
         * Editing is for campaigns that have not gone out yet. Changing the
         * audience or the template of a running campaign would mean two halves
         * of one send having different content, and the report on it could not
         * describe either honestly.
         */
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Only a draft or scheduled campaign can be edited. Clone it instead.',
            );
        }

        $campaign->fill(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'channel' => $data['channel'] ?? null,
            'template_id' => $data['template_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('audience_filters', $data)) {
            $campaign->audience_filters = $data['audience_filters'];
        }

        $campaign->updated_by = $actor?->id;
        $campaign->save();

        return $campaign->fresh();
    }

    /**
     * Starts (or resumes) dispatch.
     *
     * The audience is materialised into `campaign_recipients` on first start
     * and never rebuilt afterwards. A resumed campaign sends to the people it
     * targeted, not to whoever matches the filters now - otherwise pausing and
     * resuming quietly changes who was reached, and the campaign report stops
     * describing a single event.
     */
    public function start(Campaign $campaign, ?User $actor = null): Campaign
    {
        $this->transition($campaign, CampaignStatus::Running, $actor);

        if ($campaign->started_at === null) {
            $campaign->forceFill([
                'started_at' => now(),
                'batch_id' => (string) Str::uuid(),
            ])->save();

            $this->materialiseAudience($campaign);
        }

        $campaign->forceFill(['paused_at' => null])->save();

        DispatchCampaign::dispatch($campaign->id)->onQueue('messages');

        return $campaign->fresh();
    }

    public function pause(Campaign $campaign, ?User $actor = null): Campaign
    {
        $this->transition($campaign, CampaignStatus::Paused, $actor);

        /*
         * BR-CAMP-05: pause stops NEW dispatch. Messages already handed to the
         * provider complete and are recorded - recalling them is not something
         * a provider offers, and pretending otherwise in the report would be a
         * lie about what the recipient received.
         */
        $campaign->forceFill(['paused_at' => now()])->save();

        return $campaign->fresh();
    }

    public function stop(Campaign $campaign, ?User $actor = null): Campaign
    {
        $this->transition($campaign, CampaignStatus::Stopped, $actor);

        $campaign->forceFill(['completed_at' => now()])->save();

        return $campaign->fresh();
    }

    /**
     * Tells the campaign's owner it reached a terminal outcome (FR-NOTIF-01,
     * BR-NOTIF-02). Called by whichever recipient job finishes the campaign
     * last, right after it sets the campaign Completed - see
     * `DispatchCampaign` and `SendCampaignMessage`.
     *
     * `CampaignStatus` has no separate "failed" state - only Completed and
     * Stopped are terminal (BR-CAMP-05) - so BR-NOTIF-02's "completed or
     * failed" is read from the outcome instead: a campaign that reached zero
     * live sends against a real audience did not do its job, even though it
     * finished normally.
     */
    public function notifyOwnerOfCompletion(Campaign $campaign): void
    {
        $owner = $campaign->created_by !== null ? User::find($campaign->created_by) : null;

        if ($owner === null) {
            return;
        }

        // Re-read before quoting the counters: the caller's instance was loaded
        // when its own recipient job started, and every sibling job has been
        // incrementing these columns since. The job that happens to finish last
        // would otherwise report the totals as they stood when it began.
        $campaign->refresh();

        $failed = $campaign->total_targeted > 0 && $campaign->total_sent === 0;

        /*
         * The counters below say "sent". On an unkeyed channel that is true of
         * the record and false of the world, and this notification is where an
         * operator decides the campaign worked - so the caveat travels with the
         * numbers rather than being left for them to infer.
         */
        $caveat = $this->deliveryCaveat($campaign);

        $this->notifications->notify(
            $owner,
            $failed ? 'campaign_failed' : 'campaign_completed',
            ($failed ? 'Campaign failed: ' : 'Campaign completed: ').$campaign->name,
            [
                'body' => sprintf(
                    '%d sent, %d skipped, %d failed of %d targeted.',
                    $campaign->total_sent,
                    $campaign->total_skipped,
                    $campaign->total_failed,
                    $campaign->total_targeted,
                ).($caveat !== null ? ' '.$caveat : ''),
                'reference' => $campaign,
                'action_url' => '/campaigns/'.$campaign->id,
            ],
        );
    }

    /**
     * Clones a campaign back to draft - the only way to "restart" a stopped one.
     */
    public function clone(Campaign $campaign, ?User $actor = null): Campaign
    {
        return Campaign::create([
            'tenant_id' => $campaign->tenant_id,
            'name' => $campaign->name.' (copy)',
            'description' => $campaign->description,
            'channel' => $campaign->channel,
            'template_id' => $campaign->template_id,
            'product_id' => $campaign->product_id,
            'audience_filters' => $campaign->audience_filters,
            'status' => CampaignStatus::Draft->value,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
    }

    /**
     * Counts the audience without materialising it, for a pre-send check.
     *
     * Deliberately reports eligibility as well as size: "12,000 leads" and
     * "12,000 leads of whom 4,000 are suppressed" are different decisions, and
     * finding that out after pressing send is too late.
     *
     * @return array{total: int, eligible: int, suppressed: int, no_contact_detail: int}
     */
    public function preview(Campaign $campaign): array
    {
        $leads = $this->audienceQuery($campaign)->get();

        $suppressed = 0;
        $noDetail = 0;

        foreach ($leads as $lead) {
            $reason = $this->eligibility->reasonToSkip($lead, $campaign->channel);

            match ($reason?->value) {
                'suppressed' => $suppressed++,
                'no_contact_detail' => $noDetail++,
                default => null,
            };
        }

        return [
            'total' => $leads->count(),
            'eligible' => $leads->count() - $suppressed - $noDetail,
            'suppressed' => $suppressed,
            'no_contact_detail' => $noDetail,
        ];
    }

    /**
     * Writes one recipient row per targeted lead.
     *
     * Chunked, because the audience is the one part of a campaign that has no
     * natural size limit. `insertOrIgnore` on the unique (campaign, lead) pair
     * makes a re-run of this idempotent.
     */
    private function materialiseAudience(Campaign $campaign): void
    {
        $total = 0;

        $this->audienceQuery($campaign)->chunkById(500, function ($leads) use ($campaign, &$total) {
            $now = now();

            $rows = $leads->map(fn (Lead $lead) => [
                'campaign_id' => $campaign->id,
                'lead_id' => $lead->id,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('campaign_recipients')->insertOrIgnore($rows);
            $total += count($rows);
        });

        $campaign->forceFill(['total_targeted' => $total])->save();
    }

    /**
     * The audience, from the stored filters.
     *
     * A closed set of filters on purpose: `audience_filters` is operator input,
     * and passing it to the query builder generically would turn campaign
     * creation into an arbitrary-query primitive over the lead table.
     *
     * @return Builder<Lead>
     */
    private function audienceQuery(Campaign $campaign): Builder
    {
        $filters = $campaign->audience_filters ?? [];

        return Lead::query()
            ->when(
                isset($filters['status']),
                fn (Builder $q) => $q->whereIn(
                    'status',
                    array_values(array_intersect((array) $filters['status'], LeadStatus::values())),
                ),
            )
            ->when(isset($filters['temperature']), fn (Builder $q) => $q->whereIn('temperature', (array) $filters['temperature']))
            ->when(isset($filters['source']), fn (Builder $q) => $q->whereIn('source', (array) $filters['source']))
            ->when(isset($filters['city']), fn (Builder $q) => $q->whereIn('city', (array) $filters['city']))
            ->when(isset($filters['assigned_to']), fn (Builder $q) => $q->whereIn('assigned_to', (array) $filters['assigned_to']))
            ->when(
                isset($filters['product_id']) || $campaign->product_id !== null,
                fn (Builder $q) => $q->whereHas(
                    'products',
                    fn (Builder $p) => $p->whereIn(
                        'products.id',
                        (array) ($filters['product_id'] ?? $campaign->product_id),
                    ),
                ),
            )
            /*
             * The suppression flag is a denormalised convenience and the real
             * gate runs per recipient (BR-CAMP-02). Excluding here as well is
             * not a duplicate rule - it keeps the obviously-ineligible out of a
             * queue that would only skip them one job at a time.
             */
            ->where('is_suppressed', false);
    }

    private function transition(Campaign $campaign, CampaignStatus $target, ?User $actor): void
    {
        if (! $campaign->status->canTransitionTo($target)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf(
                    'A %s campaign cannot become %s.',
                    $campaign->status->label(),
                    $target->label(),
                ),
                // A stopped campaign is the case people hit, so say what to do
                // about it rather than only what went wrong.
                $campaign->status === CampaignStatus::Stopped
                    ? ['hint' => 'A stopped campaign is final. Clone it to run it again.']
                    : [],
            );
        }

        $campaign->forceFill([
            'status' => $target->value,
            'updated_by' => $actor?->id,
        ])->save();
    }

    /** Channels a campaign may use at all. */
    public function supportedChannels(): array
    {
        return array_values(array_filter(
            Channel::cases(),
            fn (Channel $channel) => $channel !== Channel::Call && $channel !== Channel::AiCall,
        ));
    }
}
