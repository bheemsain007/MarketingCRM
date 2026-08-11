<?php

namespace App\Enums;

/**
 * Outbound communication channels (PROJECT_REQUIREMENTS §3.5).
 *
 * Every channel here must route through DncService before dispatch (BR-DNC-01).
 * Adding a case to this enum means adding a channel to the suppression matrix in
 * DncReason - the two are deliberately coupled so a new channel cannot quietly
 * skip the gate.
 */
enum Channel: string
{
    case Call = 'call';
    case AiCall = 'ai_call';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Rcs = 'rcs';
    case Voice = 'voice';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Human Call',
            self::AiCall => 'AI Call',
            self::Sms => 'SMS',
            self::WhatsApp => 'WhatsApp',
            self::Rcs => 'RCS',
            self::Voice => 'Voice',
            self::Email => 'Email',
        };
    }

    /** Which contact detail on the lead this channel needs to be sendable. */
    public function requiredContactField(): string
    {
        return $this === self::Email ? 'email' : 'phone_e164';
    }

    /** Phone-based channels are subject to calling-hours rules (BR-CALL-04). */
    public function isPhoneBased(): bool
    {
        return $this !== self::Email;
    }

    /** Channels that place an actual call rather than send a message. */
    public function isVoiceCall(): bool
    {
        return in_array($this, [self::Call, self::AiCall], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
