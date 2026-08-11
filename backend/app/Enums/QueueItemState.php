<?php

namespace App\Enums;

/**
 * State of one lead in a dialling queue (FR-CALL-06).
 *
 * `Dialling` is the important one: it is the claim. While an item sits in this
 * state no other session may hand out the same lead, which is what makes
 * BR-CALL-03's single-assignment guarantee true rather than aspirational.
 */
enum QueueItemState: string
{
    case Pending = 'pending';
    case Dialling = 'dialling';
    case Dialled = 'dialled';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Dialling => 'In progress',
            self::Dialled => 'Called',
            self::Skipped => 'Skipped',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
