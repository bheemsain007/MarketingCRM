<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Enums\LeadStatus;

/**
 * Thrown when a lead status change violates the transition matrix (BR-STAT-02).
 */
class InvalidStatusTransitionException extends ApiException
{
    public function __construct(
        public readonly LeadStatus $from,
        public readonly LeadStatus $to,
        ?string $detail = null,
    ) {
        parent::__construct(
            ErrorCode::LeadInvalidStatusTransition,
            $detail ?? sprintf(
                'A lead cannot move from %s to %s.',
                $from->label(),
                $to->label(),
            ),
            context: [
                'from' => $from->value,
                'to' => $to->value,
                // Tells the client which moves ARE valid, so the UI can offer
                // only legal options instead of guessing.
                'allowed' => array_map(fn (LeadStatus $s) => $s->value, $from->allowedTransitions()),
            ],
        );
    }
}
