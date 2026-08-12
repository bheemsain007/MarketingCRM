<?php

namespace App\Enums;

/**
 * Campaign lifecycle (FR-CAMP-02, BR-CAMP-05).
 *
 * The one that matters is `Stopped`: it is **terminal**. A stopped campaign
 * cannot be resumed, only cloned. Resuming would restart dispatch against an
 * audience that was correct hours ago, and "stop" is what somebody reaches for
 * when a campaign is going wrong - it has to actually mean stop.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Running => 'Running',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Stopped => 'Stopped',
        };
    }

    /**
     * Allowed transitions. Anything absent is rejected with a 422.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Running, self::Stopped],
            self::Scheduled => [self::Running, self::Paused, self::Stopped],
            self::Running => [self::Paused, self::Completed, self::Stopped],
            self::Paused => [self::Running, self::Stopped],

            // Both terminal. Completed is the natural end; Stopped is the
            // deliberate one, and neither reopens.
            self::Completed, self::Stopped => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** Whether new dispatch may happen. Pause and stop both say no. */
    public function isDispatchable(): bool
    {
        return $this === self::Running;
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
