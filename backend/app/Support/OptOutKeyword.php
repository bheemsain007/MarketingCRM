<?php

namespace App\Support;

/**
 * Whether an inbound reply's first word is a DNC opt-out keyword (BR-DNC-05/07).
 *
 * Shared by every inbound path that reads a lead's raw reply text:
 * `WebhookController::inbound()` - the generic `/webhooks/inbound` endpoint that
 * actually APPLIES the opt-out - and `WhatsAppWebhookController`, which reads the
 * same keyword on the Meta Cloud API's own inbound payload purely to skip filing
 * a "STOP" as a normal conversation message and an interest signal (FR-WA-01).
 * The Cloud API path never suppresses anything itself; that stays
 * `WebhookController::inbound()`'s job alone, so a channel does not end up with
 * two suppression implementations (ADR-E).
 *
 * One list, used both places, is what keeps them from disagreeing: if a second
 * copy drifted, a lead could be opted out on one endpoint's word list while the
 * other scored the very same reply as +15 interest.
 */
class OptOutKeyword
{
    /**
     * Standard SMS/WhatsApp opt-out keywords (TCPA/CTIA convention). Kept
     * deliberately small and explicit: every entry here silently changes what
     * happens to a reply, so the list is the set of words that unambiguously
     * mean "stop", not a fuzzy guess.
     */
    private const KEYWORDS = ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'QUIT', 'END', 'OPTOUT', 'OPT-OUT'];

    /**
     * Matched on the FIRST word, case-insensitively: "STOP" and "STOP please"
     * match, but "please don't stop" does not - a keyword buried mid-sentence is
     * not a command, and treating it as one would misfire on a real customer who
     * merely mentioned the word.
     */
    public static function matches(string $text): bool
    {
        $normalised = mb_strtoupper(trim($text));

        if ($normalised === '') {
            return false;
        }

        $firstWord = preg_split('/\s+/', $normalised)[0] ?? '';

        return in_array($firstWord, self::KEYWORDS, true);
    }
}
