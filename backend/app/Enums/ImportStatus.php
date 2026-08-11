<?php

namespace App\Enums;

/**
 * Lifecycle of a bulk lead import (FR-LEAD-07).
 *
 * `Completed` and `CompletedWithErrors` are separate states rather than one
 * "done" with a counter, because they mean different things to the operator:
 * the first needs no action, the second means rows were rejected and somebody
 * has to look at the report. Collapsing them lets a half-failed import look
 * successful in a list.
 *
 * `Failed` is reserved for the import as a whole - an unreadable file, a
 * missing header. Individual bad rows never fail the import (FR-LEAD-07:
 * "never partially corrupts on failure").
 */
enum ImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
        };
    }

    /** No further rows will be processed - safe to purge the source file. */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
