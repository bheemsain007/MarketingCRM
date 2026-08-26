<?php

namespace App\Enums;

/**
 * Lifecycle of a queued lead CSV export (FR-LEAD-12).
 *
 * Mirrors ImportStatus's shape deliberately - a client polling either job
 * type learns one state machine, not two.
 */
enum ExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /** No further work will happen - the file is ready, or it never will be. */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
