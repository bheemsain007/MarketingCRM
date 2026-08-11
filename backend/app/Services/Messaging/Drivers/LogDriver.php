<?php

namespace App\Services\Messaging\Drivers;

use App\Contracts\MessageDriver;
use App\Models\Message;
use App\Services\Messaging\DeliveryResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The driver used when a channel has no credentials yet.
 *
 * This is what makes "build the integration now, add the key later" real: the
 * whole path - DNC gate, template render, queue, message record, status
 * transitions - runs end to end with nothing configured, and swapping in real
 * credentials changes which driver resolves and nothing else.
 *
 * It logs metadata only. The body of a marketing message is PII about a named
 * lead, and application logs are the least protected place it could sit
 * (SEC-PII-*, SEC-CFG-05).
 */
class LogDriver implements MessageDriver
{
    public function __construct(private readonly string $channel) {}

    public function name(): string
    {
        return 'log';
    }

    /** Always usable - that is the point of the fallback. */
    public function isConfigured(): bool
    {
        return true;
    }

    public function send(Message $message): DeliveryResult
    {
        Log::info('Outbound message not dispatched: no provider configured.', [
            'message_id' => $message->id,
            'lead_id' => $message->lead_id,
            'channel' => $this->channel,
            // Recipient and body are deliberately absent.
            'has_body' => $message->body !== null,
        ]);

        return DeliveryResult::accepted('log-'.Str::uuid());
    }
}
