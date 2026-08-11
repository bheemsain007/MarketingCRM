<?php

namespace App\Contracts;

use App\Models\Message;
use App\Services\Messaging\DeliveryResult;

/**
 * One outbound channel provider (FR-COMM-01/05/06).
 *
 * A driver does exactly one thing: hand a prepared message to a provider and
 * report what happened. It does NOT decide whether the message may be sent -
 * suppression is `DncService`'s decision and nothing else's (BR-DNC-01,
 * ADR-E). A driver that checked contactability would be a second implementation
 * of the rule, and the second one is always the one that gets it wrong.
 *
 * Drivers are also the only place allowed to know a provider's wire format, so
 * swapping Mailercloud for something else is one class, not a search through
 * the campaign engine.
 */
interface MessageDriver
{
    /** Provider name recorded on the message, e.g. "mailercloud". */
    public function name(): string;

    /** Whether credentials are present. False routes sends to the log driver. */
    public function isConfigured(): bool;

    public function send(Message $message): DeliveryResult;
}
