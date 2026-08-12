<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignSkipReason;
use App\Enums\Channel;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Dnc\DncService;
use App\Services\Messaging\OutboundMessageService;
use App\Services\Settings\SettingsService;

/**
 * Whether one lead may receive one campaign message (BR-CAMP-01/04, FR-CAMP-03).
 *
 * Separate from CampaignService because it is asked at a different TIME. The
 * service resolves an audience once; this is asked again per recipient at
 * dispatch, when the answer may have changed (BR-CAMP-02).
 *
 * It answers with a REASON rather than a boolean. A campaign report that says
 * 4,000 leads were skipped is nearly useless; one that says 3,800 were
 * suppressed and 200 had no mobile number tells an operator what to fix.
 */
class CampaignEligibility
{
    public function __construct(
        private readonly DncService $dnc,
        private readonly OutboundMessageService $messages,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Why this lead cannot be sent to, or null if it can.
     *
     * Order matters: suppression is checked first so that a lead who has opted
     * out is never reported as merely frequency-capped. The reason recorded
     * against a lead is the one somebody will act on.
     */
    public function reasonToSkip(Lead $lead, Channel $channel): ?CampaignSkipReason
    {
        // BR-DNC-01 through the one gate every outbound path uses (ADR-E).
        if (! $this->dnc->canContact($lead, $channel)) {
            return CampaignSkipReason::Suppressed;
        }

        if ($this->messages->recipientFor($lead, $channel) === null) {
            return CampaignSkipReason::NoContactDetail;
        }

        if ($this->isFrequencyCapped($lead, $channel)) {
            return CampaignSkipReason::FrequencyCapped;
        }

        return null;
    }

    /**
     * BR-CAMP-04: at most N campaign messages per channel per rolling 24h, and
     * M per rolling week.
     *
     * **Rolling windows, not calendar ones.** A calendar-day cap lets two
     * campaigns at 23:50 and 00:10 land twenty minutes apart and both count as
     * "one a day", which is exactly the experience the cap exists to prevent.
     *
     * Transactional messages are exempt, and the exemption is structural: only
     * rows carrying a `campaign_id` are counted. A payment receipt cannot
     * consume somebody's marketing allowance, and a marketing send cannot hide
     * behind being transactional.
     */
    private function isFrequencyCapped(Lead $lead, Channel $channel): bool
    {
        $perDay = (int) $this->settings->get('crm.campaign_caps.per_day', 2);
        $perWeek = (int) $this->settings->get('crm.campaign_caps.per_week', 5);

        // A cap of zero means "no cap", not "block everything" - a
        // misconfiguration should not silently stop all marketing.
        if ($perDay <= 0 && $perWeek <= 0) {
            return false;
        }

        $countSince = fn ($since) => Message::query()
            ->where('lead_id', $lead->id)
            ->where('channel', $channel->value)
            ->whereNotNull('campaign_id')
            // Skipped rows are not receipts - a lead who was skipped yesterday
            // did not get a message and must not be capped for it.
            ->whereIn('status', ['queued', 'sent', 'delivered', 'read', 'replied'])
            ->where('created_at', '>=', $since)
            ->count();

        if ($perDay > 0 && $countSince(now()->subDay()) >= $perDay) {
            return true;
        }

        return $perWeek > 0 && $countSince(now()->subWeek()) >= $perWeek;
    }
}
