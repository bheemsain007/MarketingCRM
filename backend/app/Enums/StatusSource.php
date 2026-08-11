<?php

namespace App\Enums;

/**
 * What caused a status change (`lead_status_history.source_channel`).
 *
 * Distinct from `Channel`: that enum lists the outbound channels DNC can
 * suppress, and answering "may we send an SMS?" is a different question from
 * "what moved this lead?". A status change can come from a channel, but it can
 * also come from a person typing in the CRM, from an import, or from the
 * system itself - none of which are channels.
 *
 * This matters for attribution (GLOSSARY §2.6): a conversion driven by an AI
 * call is not the same as one a telecaller worked by hand, and reporting has to
 * be able to tell them apart after the fact.
 */
enum StatusSource: string
{
    case Manual = 'manual';
    case Call = 'call';
    case AiCall = 'ai_call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Sms = 'sms';
    case Rcs = 'rcs';
    case Voice = 'voice';
    case Import = 'import';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Call => 'Call',
            self::AiCall => 'AI Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::Rcs => 'RCS',
            self::Voice => 'Voice',
            self::Import => 'Import',
            self::System => 'System',
        };
    }

    /**
     * True when no human made this change.
     *
     * System changes are recorded with a null actor rather than being
     * attributed to whoever happened to trigger them - crediting a telecaller
     * for an automatic advance would corrupt attribution.
     */
    public function isAutomatic(): bool
    {
        return $this === self::System;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
