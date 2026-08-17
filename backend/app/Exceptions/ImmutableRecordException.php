<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An append-only record was asked to change (SEC-AUD-01).
 *
 * Deliberately NOT an ApiException. Those describe a business rule a user can
 * violate and carry an HTTP status and a message meant to be read by whoever
 * made the request. This describes a caller doing something the domain says is
 * impossible - editing a compliance audit entry - which is a defect in our own
 * code, not user input, and it should surface as a 500 and a stack trace rather
 * than as a tidy 422 somebody might learn to expect.
 *
 * Silently cancelling the write (returning false from the model event) was the
 * alternative and is worse: the caller carries on believing it succeeded, and
 * the rule is only discovered by whoever later notices the row never changed.
 */
class ImmutableRecordException extends RuntimeException
{
    public static function for(string $table, string $operation): self
    {
        return new self(sprintf(
            '%s is append-only (SEC-AUD-01): a row may be created but never %s. '
            .'If a correction is needed, write a new entry describing it.',
            $table,
            $operation,
        ));
    }
}
