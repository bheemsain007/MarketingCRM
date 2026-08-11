<?php

namespace App\Enums;

/**
 * Auto-dialer session lifecycle (FR-CALL-06).
 *
 * `Paused` and `Stopped` are different states, not two words for the same
 * thing: a paused run keeps its queue position and can be resumed, a stopped
 * one is finished. Collapsing them would make "pause halts dialling without
 * losing queue position" unimplementable.
 */
enum DialerState: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Paused => 'Paused',
            self::Stopped => 'Stopped',
            self::Completed => 'Completed',
        };
    }

    /** A run that is still somebody's open work. */
    public function isActive(): bool
    {
        return in_array($this, [self::Running, self::Paused], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Stopped, self::Completed], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
