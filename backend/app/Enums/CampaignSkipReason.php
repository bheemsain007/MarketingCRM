<?php

namespace App\Enums;

/**
 * Why a targeted lead did not receive a campaign message (BR-DNC-05, FR-CAMP-03).
 *
 * A closed set, because these are reported on. "Nothing happened" is never an
 * acceptable outcome for a targeted lead - every one either gets a message or
 * gets a reason, and a free-text reason is one nobody can group by.
 */
enum CampaignSkipReason: string
{
    case Suppressed = 'suppressed';
    case NoContactDetail = 'no_contact_detail';
    case FrequencyCapped = 'frequency_capped';
    case CampaignNotRunning = 'campaign_not_running';

    public function label(): string
    {
        return match ($this) {
            self::Suppressed => 'On the do-not-contact list',
            self::NoContactDetail => 'No usable address for this channel',
            self::FrequencyCapped => 'Frequency cap reached',
            self::CampaignNotRunning => 'Campaign paused or stopped before dispatch',
        };
    }

    /**
     * Whether the lead might qualify later.
     *
     * A frequency cap lifts tomorrow and a paused campaign may resume; a
     * suppression does not expire on its own, and a missing phone number will
     * not appear by itself. Useful for deciding what a re-run should retry.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::FrequencyCapped, self::CampaignNotRunning => true,
            self::Suppressed, self::NoContactDetail => false,
        };
    }
}
