<?php

namespace App\Services\Messaging;

use App\Contracts\MessageDriver;
use App\Enums\Channel;
use App\Services\Messaging\Drivers\BhashSmsDriver;
use App\Services\Messaging\Drivers\LogDriver;
use App\Services\Messaging\Drivers\MailercloudDriver;
use App\Services\Messaging\Drivers\RcsDriver;
use App\Services\Messaging\Drivers\VoiceDriver;
use App\Services\Messaging\Drivers\WhatsAppDriver;
use App\Services\Settings\SettingsService;

/**
 * Resolves the driver for a channel (FR-COMM-05).
 *
 * An unconfigured channel falls back to `LogDriver` rather than throwing. That
 * is deliberate: eight phases are blocked on credentials nobody has yet, and
 * the alternative to a fallback is either stubbing each integration or refusing
 * to build them until the keys arrive. With the fallback, the entire path is
 * exercised now and adding a key changes which driver resolves and nothing
 * else.
 *
 * The fallback is visible, not silent - a message sent through it records
 * `provider = "log"`, so "why did nobody receive this?" is answerable from the
 * message row.
 */
class MessageDriverManager
{
    public function __construct(private readonly SettingsService $settings) {}

    public function for(Channel $channel): MessageDriver
    {
        $driver = $this->providerFor($channel);

        return $driver !== null && $driver->isConfigured()
            ? $driver
            : new LogDriver($channel->value);
    }

    /** Whether the channel has a real provider behind it right now. */
    public function isLive(Channel $channel): bool
    {
        return $this->providerFor($channel)?->isConfigured() ?? false;
    }

    private function providerFor(Channel $channel): ?MessageDriver
    {
        return match ($channel) {
            Channel::Email => new MailercloudDriver($this->settings),
            Channel::Sms => new BhashSmsDriver($this->settings),
            Channel::WhatsApp => new WhatsAppDriver($this->settings),
            Channel::Rcs => new RcsDriver($this->settings),
            Channel::Voice => new VoiceDriver($this->settings),

            // Phase 24 (AI calling) still lands as one more arm here; nothing
            // else in the send path changes. The interactive calling channels
            // (Call/AiCall) are not message channels and have no driver.
            default => null,
        };
    }
}
