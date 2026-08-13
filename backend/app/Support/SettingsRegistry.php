<?php

namespace App\Support;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Services\Dnc\SuppressionMatrix;

/**
 * The allowlist of settable keys, grouped for the settings screen.
 *
 * Every key here overrides the config key of the same name, so a definition is
 * only ever added alongside a real `config/*` entry - a setting with no config
 * default would resolve to null once the row is cleared.
 *
 * **Provider groups are deliberately generic where the vendor is unnamed**
 * (T-31 WhatsApp BSP, T-32 RCS, T-33 Voice, T-34 payment gateway). An endpoint
 * and a key is the shape almost every provider takes; inventing vendor-specific
 * fields before the vendor is chosen would mean rewriting them when it is.
 */
class SettingsRegistry
{
    /** @return array<int, SettingDefinition> */
    public static function all(): array
    {
        return array_merge(self::operational(), self::credentials());
    }

    /** @return array<int, SettingDefinition> */
    public static function operational(): array
    {
        return [
            new SettingDefinition('crm.timezone', 'organisation', 'Organisation timezone', 'string',
                'Reports aggregate in this zone. Lead-local time still governs calling hours.'),

            new SettingDefinition('crm.calling_hours.start', 'calling', 'Calling hours start', 'time',
                'Enforced in the LEAD\'s timezone (BR-CALL-04).'),
            new SettingDefinition('crm.calling_hours.end', 'calling', 'Calling hours end', 'time'),
            new SettingDefinition('crm.contact_cooldown_hours', 'calling', 'Contact cooldown (hours)', 'int',
                'The auto dialer skips a lead contacted more recently than this.'),

            new SettingDefinition('crm.dialer.max_queue_size', 'dialer', 'Maximum queue size', 'int',
                'A run is a shift\'s work, not the whole database.'),
            new SettingDefinition('crm.dialer.claim_ttl_minutes', 'dialer', 'Claim expiry (minutes)', 'int',
                'How long a claimed lead stays claimed before another telecaller may take it.'),

            new SettingDefinition('crm.assignment.method', 'assignment', 'Auto-assignment method', 'select',
                'BR-ASSIGN-01. "manual" assigns nobody.',
                ['manual', 'round_robin', 'load_balanced']),
            new SettingDefinition('crm.assignment.open_lead_cap', 'assignment', 'Open-lead cap per telecaller', 'int',
                'At or above this, a telecaller is skipped by auto-assignment (BR-ASSIGN-02).'),

            new SettingDefinition('crm.campaign_caps.per_day', 'campaigns', 'Campaign messages per lead per day', 'int',
                'BR-CAMP-04. Transactional messages are exempt.'),
            new SettingDefinition('crm.campaign_caps.per_week', 'campaigns', 'Campaign messages per lead per week', 'int'),

            new SettingDefinition('crm.follow_up.reminder_lead_minutes', 'follow_ups', 'Reminder lead time (minutes)', 'int'),
            new SettingDefinition('crm.idle_threshold_minutes', 'follow_ups', 'Idle threshold (minutes)', 'int',
                'A gap longer than this between tracked actions counts as idle.'),

            new SettingDefinition('crm.discount_approval_threshold', 'sales', 'Discount approval threshold (%)', 'float',
                'Discounts above this need Manager+ approval (BR-SALE-03).'),
            new SettingDefinition('crm.ai_interest_confidence_threshold', 'sales', 'AI interest confidence threshold', 'float',
                'Below this, AI-detected interest is recorded but does not move lead status (BR-INT-04).'),

            new SettingDefinition('crm.recordings.retention_days', 'recordings', 'Recording retention (days)', 'int',
                'Indefinite retention is never the default (BR-REC-02).'),

            new SettingDefinition('crm.attribution.model', 'reporting', 'Conversion attribution', 'select',
                'Who is credited when a lead changed hands (GLOSSARY 2.6). This decides what people '
                .'are paid - confirm before it drives commission (T-24).',
                ['last_owner', 'first_interest']),

            ...self::dncMatrix(),
        ];
    }

    /**
     * The tunable half of the DNC matrix (BR-DNC-04, T-65).
     *
     * `Do Not Contact` and `Opted Out` are deliberately absent: they are a
     * person's explicit instruction, they stay absolute in code, and no
     * settings write may narrow them. Only reasons that are OUR inference from
     * an outcome are listed here.
     *
     * @return array<int, SettingDefinition>
     */
    private static function dncMatrix(): array
    {
        $channels = array_map(fn (Channel $channel) => $channel->value, Channel::cases());

        return array_map(
            fn (DncReason $reason) => new SettingDefinition(
                SuppressionMatrix::SETTING_PREFIX.$reason->value,
                'dnc',
                sprintf('"%s" blocks', $reason->label()),
                'channels',
                'Channels this reason suppresses. Empty restores the built-in list; an unknown channel is ignored and the built-in list is used, so suppression never narrows by accident.',
                $channels,
            ),
            array_values(array_filter(DncReason::cases(), fn (DncReason $reason) => $reason->isConfigurable())),
        );
    }

    /**
     * Provider credentials - Super Admin only (SEC-AUTHZ-06).
     *
     * @return array<int, SettingDefinition>
     */
    public static function credentials(): array
    {
        return [
            // Phase 13
            self::credential('providers.mailercloud.api_key', 'email', 'Mailercloud API key', 'secret'),
            self::credential('providers.mailercloud.from_email', 'email', 'From address', 'string'),
            self::credential('providers.mailercloud.from_name', 'email', 'From name', 'string'),
            self::credential('providers.mailercloud.webhook_secret', 'email', 'Delivery webhook secret', 'secret',
                'Presented as X-Webhook-Token on delivery callbacks. Until it is set, the webhook refuses everything.'),

            // Phase 15
            self::credential('providers.bhashsms.user', 'sms', 'BhashSMS user', 'string'),
            self::credential('providers.bhashsms.password', 'sms', 'BhashSMS password', 'secret'),
            self::credential('providers.bhashsms.sender_id', 'sms', 'Sender ID', 'string',
                'The 6-character DLT-registered header.'),

            // Phase 14 - BSP not chosen (T-31)
            self::credential('providers.whatsapp.driver', 'whatsapp', 'BSP (WHATSAPP_PROVIDER)', 'select',
                'Not yet chosen (T-31). Start Meta business verification early — approval takes weeks (T-36).',
                ['meta_cloud', 'gupshup', 'interakt', 'other']),
            self::credential('providers.whatsapp.phone_number_id', 'whatsapp', 'Phone number ID', 'string'),
            self::credential('providers.whatsapp.token', 'whatsapp', 'Access token (WHATSAPP_API_KEY)', 'secret'),
            self::credential('providers.whatsapp.webhook_verify_token', 'whatsapp', 'Webhook verify token', 'secret'),
            self::credential('providers.whatsapp.app_secret', 'whatsapp', 'App secret', 'secret'),

            // Phase 12
            self::credential('providers.meta.app_id', 'meta', 'Meta app ID', 'string'),
            self::credential('providers.meta.app_secret', 'meta', 'Meta app secret', 'secret'),
            self::credential('providers.meta.verify_token', 'meta', 'Webhook verify token', 'secret',
                'Echoed back to Meta during webhook subscription (SEC-WH-*).'),
            self::credential('providers.meta.page_access_token', 'meta', 'Page access token', 'secret'),

            // Phases 16/17 - vendors not chosen (T-32, T-33)
            self::credential('providers.rcs.provider', 'rcs', 'RCS provider', 'string',
                'Vendor not chosen (T-32). Name the provider once it is.'),
            self::credential('providers.rcs.api_key', 'rcs', 'RCS API key', 'secret'),
            self::credential('providers.voice.provider', 'voice', 'Voice provider', 'string',
                'Vendor not chosen (T-33).'),
            self::credential('providers.voice.api_key', 'voice', 'Voice API key', 'secret'),

            // Phase 19 - inbound keyword opt-out (BR-DNC-05/07)
            self::credential('providers.inbound.webhook_secret', 'dnc', 'Inbound webhook secret', 'secret',
                'Presented as X-Webhook-Token on inbound STOP callbacks. Until it is set, the inbound endpoint refuses everything.'),

            // Phase 24
            self::credential('providers.vaaad.api_key', 'ai_calling', 'Vaaad API key', 'secret'),
            self::credential('providers.vaaad.webhook_secret', 'ai_calling', 'Vaaad webhook secret', 'secret'),

            // Phase 23 - Razorpay is the default (T-34); the others are listed
            // but have no driver, and selecting one refuses rather than
            // silently collecting through Razorpay instead.
            self::credential('providers.payment.gateway', 'payment', 'Payment gateway', 'select',
                'Razorpay is the default and the only one implemented (T-34).',
                ['razorpay', 'payu', 'stripe', 'other']),
            self::credential('providers.payment.key_id', 'payment', 'Key ID', 'string',
                'Razorpay: the public key id (rzp_live_...). Until this and the secret are set, payment links refuse.'),
            self::credential('providers.payment.key_secret', 'payment', 'Key secret', 'secret'),
            self::credential('providers.payment.webhook_secret', 'payment', 'Webhook signing secret', 'secret',
                'The secret entered in the gateway dashboard when subscribing the webhook. Until it is set, the collection endpoint rejects everything - an unsigned endpoint that can mark payments paid would settle any sale in the system.'),
        ];
    }

    /**
     * Every definition in credentials() carries the flag, secret or not - the
     * whole provider group is credential configuration (SEC-AUTHZ-06).
     *
     * @param  array<int, string>  $options
     */
    private static function credential(
        string $key,
        string $group,
        string $label,
        string $type = 'string',
        ?string $help = null,
        array $options = [],
    ): SettingDefinition {
        return new SettingDefinition($key, $group, $label, $type, $help, $options, credential: true);
    }

    public static function find(string $key): ?SettingDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Definitions grouped for the settings screen, in registry order.
     *
     * @return array<string, array<int, SettingDefinition>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::all() as $definition) {
            $groups[$definition->group][] = $definition;
        }

        return $groups;
    }

    /** Human labels for the group tabs. */
    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'organisation' => 'Organisation',
            'calling' => 'Calling',
            'dialer' => 'Auto dialer',
            'assignment' => 'Lead assignment',
            'campaigns' => 'Campaigns',
            'follow_ups' => 'Follow-ups & productivity',
            'sales' => 'Sales & AI',
            'recordings' => 'Recordings',
            'email' => 'Email (Mailercloud)',
            'sms' => 'SMS (BhashSMS)',
            'whatsapp' => 'WhatsApp',
            'meta' => 'Facebook / Instagram',
            'rcs' => 'RCS',
            'voice' => 'Voice',
            'ai_calling' => 'AI calling (Vaaad)',
            'payment' => 'Payment gateway',
            'dnc' => 'Inbound opt-out',
            default => ucfirst($group),
        };
    }
}
