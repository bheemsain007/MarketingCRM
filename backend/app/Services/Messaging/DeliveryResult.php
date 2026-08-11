<?php

namespace App\Services\Messaging;

/**
 * What a driver reports back about one send attempt.
 *
 * `accepted` means the PROVIDER took the message, not that it reached anybody.
 * Delivery is a later, asynchronous fact that arrives by webhook (FR-COMM-03) -
 * conflating the two would mark every queued message "delivered" the moment the
 * API returned 200.
 */
class DeliveryResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureReason = null,
        public readonly ?float $cost = null,
        public readonly bool $retryable = false,
    ) {}

    public static function accepted(?string $providerMessageId = null, ?float $cost = null): self
    {
        return new self(true, $providerMessageId, cost: $cost);
    }

    /**
     * A permanent refusal - a malformed address, a rejected template. Retrying
     * produces the same answer, so the job must not.
     */
    public static function rejected(string $reason): self
    {
        return new self(false, failureReason: $reason, retryable: false);
    }

    /**
     * A transient failure - timeout, 5xx, rate limit. Worth another attempt
     * with backoff (FR-COMM-06).
     */
    public static function failed(string $reason): self
    {
        return new self(false, failureReason: $reason, retryable: true);
    }
}
