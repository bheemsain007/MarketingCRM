<?php

namespace App\Services\Payments\Gateways;

use Illuminate\Support\Carbon;

/**
 * What a gateway hands back when it accepts a payment link request (FR-PAY-02).
 *
 * `id` is the LINK's id, not a payment's. The distinction is the reason
 * `payment_links` exists as its own table: Razorpay issues `plink_...` when the
 * link is created and a separate `pay_...` when somebody actually pays it, and
 * `payments` has room for exactly one gateway id in its unique
 * `(gateway, gateway_payment_id)` pair. Collapsing the two ids into that one
 * column would either lose the link id or make the uniqueness guarantee - the
 * thing stopping a redelivered webhook double-recording money - meaningless.
 */
class GatewayLink
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly ?Carbon $expiresAt = null,
        /** @var array<string, mixed> The provider's own response, kept for reconciliation. */
        public readonly array $raw = [],
    ) {}
}
