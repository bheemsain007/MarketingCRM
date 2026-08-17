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

    /*
     * BR-CAMP-01 names "the lead is not archived" as an eligibility condition,
     * but until this case existed there was no way to say it: a lead archived
     * after the audience was built left its recipient row pending for ever,
     * which is the one outcome BR-DNC-05 forbids.
     */
    case LeadArchived = 'lead_archived';

    public function label(): string
    {
        return match ($this) {
            self::Suppressed => 'On the do-not-contact list',
            self::NoContactDetail => 'No usable address for this channel',
            self::FrequencyCapped => 'Frequency cap reached',
            self::CampaignNotRunning => 'Campaign paused or stopped before dispatch',
            self::LeadArchived => 'Lead archived before dispatch',
        };
    }

    /**
     * Whether the lead might qualify later.
     *
     * A frequency cap lifts tomorrow and a paused campaign may resume; a
     * suppression does not expire on its own, and a missing phone number will
     * not appear by itself. Useful for deciding what a re-run should retry.
     *
     * Archiving sits with the permanent reasons even though a lead can in
     * principle be restored. Somebody archived that lead on purpose, and a
     * re-run that quietly picked it back up would contact a person the CRM had
     * been told to put away - the restore has to be the deliberate act, not the
     * retry.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::FrequencyCapped, self::CampaignNotRunning => true,
            self::Suppressed, self::NoContactDetail, self::LeadArchived => false,
        };
    }
}
