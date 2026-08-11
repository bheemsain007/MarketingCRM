<?php

namespace App\Services\Dnc;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * The reason x channel matrix (BR-DNC-02, BR-DNC-04).
 *
 * BR-DNC-04 requires this policy to be configuration rather than code, so that
 * changing whether "Not Interested" blocks manual calls is a settings change
 * and not a deploy. It is honoured here **in part, deliberately** (T-65).
 *
 * Suppression is the compliance-critical rule in this system. Making the whole
 * matrix runtime-editable would mean a single settings write - with no code
 * review anywhere in the path - could re-enable contacting people who
 * explicitly opted out. So:
 *
 * - `DncReason::isConfigurable()` decides which reasons may be tuned at all.
 *   An explicit "do not contact" or "opt out" is absolute and stays in code.
 * - Everything else reads its channel list from settings, falling back to the
 *   enum's list whenever the stored value is missing, empty or unparseable.
 *
 * Every fallback is loud. Silently narrowing suppression because a setting was
 * mistyped is the failure this class exists to prevent.
 */
class SuppressionMatrix
{
    public const SETTING_PREFIX = 'crm.dnc.matrix.';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Channels this reason blocks, after any permitted override.
     *
     * @return array<int, Channel>
     */
    public function blockedChannels(DncReason $reason): array
    {
        $default = $reason->blockedChannels();

        if (! $reason->isConfigurable()) {
            return $default;
        }

        $configured = $this->settings->get(self::SETTING_PREFIX.$reason->value);

        if (! is_string($configured) || trim($configured) === '') {
            return $default;
        }

        $channels = [];
        $unknown = [];

        foreach (explode(',', $configured) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $channel = Channel::tryFrom($token);

            if ($channel === null) {
                $unknown[] = $token;

                continue;
            }

            $channels[] = $channel;
        }

        if ($unknown !== []) {
            /*
             * Fail to the DEFAULT, not to the part that parsed. A typo in a
             * six-channel list would otherwise silently unblock whichever
             * channel was misspelled, and the first evidence of it would be a
             * suppressed lead being contacted.
             */
            Log::warning('DNC matrix override contains unknown channels; using the built-in list instead.', [
                'reason' => $reason->value,
                'unknown' => $unknown,
            ]);

            return $default;
        }

        if ($channels === []) {
            return $default;
        }

        return array_values(array_unique($channels, SORT_REGULAR));
    }

    public function blocks(DncReason $reason, Channel $channel): bool
    {
        return in_array($channel, $this->blockedChannels($reason), true);
    }
}
