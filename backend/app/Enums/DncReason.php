<?php

namespace App\Enums;

/**
 * Suppression reasons and their channel scope (BR-DNC-02).
 *
 * This is the single most important rule in the system. Suppression is per
 * REASON and per CHANNEL - a wrong phone number must not block a valid email
 * address, and a bounced email must not stop us calling.
 *
 * DncService consults this matrix. No channel module may reimplement it (ADR-E).
 *
 * PROPOSED - awaiting sign-off. Whether "Not Interested" should block manual
 * human calls (currently yes) is the open question worth confirming first.
 */
enum DncReason: string
{
    case DoNotContact = 'do_not_contact';
    case NotInterested = 'not_interested';
    case OptedOut = 'opted_out';
    case WrongNumber = 'wrong_number';
    case InvalidNumber = 'invalid_number';
    case BouncedEmail = 'bounced_email';

    public function label(): string
    {
        return match ($this) {
            self::DoNotContact => 'Do Not Contact',
            self::NotInterested => 'Not Interested',
            self::OptedOut => 'Opted Out',
            self::WrongNumber => 'Wrong Number',
            self::InvalidNumber => 'Invalid Number',
            self::BouncedEmail => 'Bounced Email',
        };
    }

    /**
     * Channels this reason blocks.
     *
     * An empty array would mean "blocks nothing" - no reason does that. A reason
     * returning every channel is an absolute block.
     *
     * @return array<int, Channel>
     */
    public function blockedChannels(): array
    {
        return match ($this) {
            // Absolute - blocks everything, in every context.
            self::DoNotContact, self::NotInterested, self::OptedOut => Channel::cases(),

            // The phone is bad; the email address may be perfectly good.
            self::WrongNumber, self::InvalidNumber => [
                Channel::Call, Channel::AiCall, Channel::Sms,
                Channel::WhatsApp, Channel::Rcs, Channel::Voice,
            ],

            // A hard bounce says nothing about the phone number.
            self::BouncedEmail => [Channel::Email],
        };
    }

    public function blocks(Channel $channel): bool
    {
        return in_array($channel, $this->blockedChannels(), true);
    }

    /**
     * Whether this reason's channel list may be changed at runtime (BR-DNC-04).
     *
     * BR-DNC-04 asks for the matrix to be configuration, so that changing
     * whether "Not Interested" blocks manual calls is not a deploy. Applied to
     * EVERY reason it would make "stop contacting me" a toggle: one bad
     * settings write, with no code review in the path, and the system starts
     * ringing people who opted out.
     *
     * So the split is by what the reason means, not by convenience:
     *
     * - `DoNotContact` and `OptedOut` are a person's explicit instruction.
     *   They are absolute, they stay in code, and no setting can narrow them.
     * - The rest are OUR inference from an outcome - a bounce, a wrong number,
     *   a "not interested" on one call. Inferences are exactly what an
     *   operator should be able to tune without a deploy.
     */
    public function isConfigurable(): bool
    {
        return match ($this) {
            self::DoNotContact, self::OptedOut => false,
            self::NotInterested, self::WrongNumber, self::InvalidNumber, self::BouncedEmail => true,
        };
    }

    /**
     * Whether removing this suppression requires elevated authority
     * (BR-DNC-06). An explicit "do not contact" is never undone casually.
     */
    public function requiresElevatedRemoval(): bool
    {
        return in_array($this, [self::DoNotContact, self::OptedOut], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
