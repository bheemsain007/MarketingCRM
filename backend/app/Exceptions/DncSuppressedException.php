<?php

namespace App\Exceptions;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\ErrorCode;

/**
 * Thrown when an outbound action targets a suppressed lead (BR-DNC-01).
 *
 * This exists as a distinct type because it is the one failure the system must
 * never swallow: catching it broadly and continuing would defeat the entire DNC
 * guarantee. Callers that legitimately handle it (campaign dispatch, which logs
 * a skip rather than failing) catch this specific class, not \Exception.
 *
 * The reason and channel are exposed in the response payload so the UI can tell
 * a telecaller *why* a lead cannot be contacted rather than just refusing.
 */
class DncSuppressedException extends ApiException
{
    public function __construct(
        public readonly int $leadId,
        public readonly Channel $channel,
        public readonly ?DncReason $reason = null,
    ) {
        parent::__construct(
            ErrorCode::DncSuppressed,
            $reason
                ? sprintf('This lead cannot be contacted on %s: %s.', $channel->label(), $reason->label())
                : sprintf('This lead cannot be contacted on %s.', $channel->label()),
            context: [
                'lead_id' => $leadId,
                'channel' => $channel->value,
                'reason' => $reason?->value,
            ],
        );
    }
}
