<?php

namespace App\Services\Messaging\Drivers;

use App\Contracts\MessageDriver;
use App\Enums\Channel;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Template;
use App\Services\Messaging\DeliveryResult;
use App\Services\Messaging\OutboundMessageService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp via the Meta Cloud API (Phase 14, FR-WA-01, FR-COMM-01..06).
 *
 * Like the other channels this is one driver class - the gate, queue,
 * idempotency and status transitions were built in Phase 13 and are
 * channel-agnostic. The whole channel is one arm in `MessageDriverManager` and
 * one credential set.
 *
 * **The BSP is not chosen yet (T-31).** This implements the *Meta direct* shape
 * because the credential names Phase 1 settled on - `phone_number_id`, a bearer
 * `token`, `app_secret` - are the Cloud API's own, so an operator who fills in
 * the documented `.env` keys gets a working channel. If a BSP (Gupshup,
 * Interakt) is chosen instead, that is a different endpoint and request body,
 * i.e. another driver class here - nothing outside this file changes.
 *
 * **The wire format is provisional** until the account is live (T-35): this is
 * the conventional `/{phone_number_id}/messages` text-message shape.
 *
 * **The 24-hour customer-service window (FR-WA-01).** Meta accepts a free-form
 * `text` message only while the window opened by the LEAD's own last inbound
 * message is still open; outside it, only a pre-approved `template` message is
 * accepted; every other channel is either not phone-based or has no equivalent
 * rule, so this lives on the driver rather than in the shared send path. The
 * window is derived from `messages` itself - the lead's last inbound WhatsApp
 * row - rather than a new column: `WhatsAppWebhookController` already writes
 * that row (direction=inbound) for every genuine reply, so the fact already
 * exists where every other "last X" fact in this schema lives, and there is
 * nothing to keep in sync. `last_engagement_at` on `Lead` was considered and
 * rejected for this: it is a cross-channel, business-rule-gated signal (an
 * AI call or an SMS reply moves it too, and a low-confidence AI signal does
 * not), not the raw "did WhatsApp specifically hear from this lead" fact Meta's
 * rule actually turns on - using it here would let an unrelated channel's
 * engagement wrongly open (or a WhatsApp-only conversation wrongly fail to
 * open) this channel's window.
 */
class WhatsAppDriver implements MessageDriver
{
    /**
     * Graph API host and version. The phone-number id is per-account and comes
     * from settings, so only the version is pinned here - Meta deprecates old
     * versions on a schedule, and a silently-floating version is a channel that
     * breaks without a deploy.
     */
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    /** Meta's customer-service window (FR-WA-01). Not configurable - it is Meta's rule, not ours. */
    private const CUSTOMER_SERVICE_WINDOW_HOURS = 24;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly OutboundMessageService $outbound,
    ) {}

    public function name(): string
    {
        // Records which integration actually sent the row. Defaults to the Meta
        // Cloud API label; if a BSP is configured its name is recorded instead,
        // so "who sent this?" stays answerable once the vendor is chosen.
        return (string) ($this->settings->get('providers.whatsapp.driver') ?: 'whatsapp_cloud');
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured('providers.whatsapp.token')
            && $this->settings->isConfigured('providers.whatsapp.phone_number_id');
    }

    public function send(Message $message): DeliveryResult
    {
        // The Cloud API wants the number in international format, digits only -
        // no leading '+'. Storage is E.164, so the difference is exactly that
        // character.
        $to = ltrim((string) $message->recipient, '+');

        $body = $this->windowIsOpen($message) ? $this->textBody($message) : $this->templateBody($message);

        // A refusal decided locally, before any HTTP call - the whole point.
        // Today, without this, the same request reaches Meta and comes back an
        // ordinary 4xx with no signal an operator can act on; this refusal
        // names exactly what is missing instead (FR-WA-01).
        if ($body instanceof DeliveryResult) {
            return $body;
        }

        try {
            $response = Http::withToken((string) $this->settings->get('providers.whatsapp.token'))
                ->timeout(15)
                ->post(self::GRAPH.'/'.$this->settings->get('providers.whatsapp.phone_number_id').'/messages', array_merge([
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                ], $body));
        } catch (Throwable $e) {
            // Connection-level failure: nothing was accepted, so retrying is
            // safe and correct (FR-COMM-06).
            return DeliveryResult::failed('Could not reach WhatsApp: '.$e->getMessage());
        }

        if ($response->successful()) {
            // { "messages": [ { "id": "wamid.XXX" } ] } - the wamid is echoed
            // back on the delivery webhook, so it is what we key status on.
            return DeliveryResult::accepted($response->json('messages.0.id'));
        }

        // 4xx is Meta refusing the request itself - an unregistered number, a
        // 24-hour-window violation, a bad template. The same request retried is
        // the same refusal, so it is rejected rather than failed.
        if ($response->clientError()) {
            return DeliveryResult::rejected(
                'WhatsApp rejected the message ('.$response->status().'): '
                .$this->errorFrom($response).'.',
            );
        }

        return DeliveryResult::failed('WhatsApp returned '.$response->status().'.');
    }

    /**
     * The Graph API reports the reason in `error.message`; fall back to the raw
     * body so a shape we did not expect is still recorded rather than swallowed.
     */
    private function errorFrom($response): string
    {
        return (string) ($response->json('error.message')
            ?? mb_substr((string) $response->body(), 0, 180));
    }

    /**
     * Whether the LEAD opened the customer-service window recently enough that
     * free-form text is still acceptable (FR-WA-01).
     *
     * Meta's rule keys on the lead's own last inbound message, never on
     * anything we sent - a channel we spam does not extend our own licence to
     * keep spamming it. `sent_at` on an inbound row is the moment
     * `WhatsAppWebhookController` recorded the lead as having sent it (from
     * Meta's own `timestamp` field where present), so this is exactly that
     * fact, not an approximation of it.
     */
    private function windowIsOpen(Message $message): bool
    {
        $lastInboundAt = Message::query()
            ->where('lead_id', $message->lead_id)
            ->where('channel', Channel::WhatsApp->value)
            ->where('direction', 'inbound')
            ->max('sent_at');

        return $lastInboundAt !== null
            && Carbon::parse($lastInboundAt)->gt(now()->subHours(self::CUSTOMER_SERVICE_WINDOW_HOURS));
    }

    /** @return array<string, mixed> */
    private function textBody(Message $message): array
    {
        return [
            'type' => 'text',
            'text' => ['body' => (string) $message->body],
        ];
    }

    /**
     * The `template` message body for a send outside the window, or a refusal.
     *
     * Never falls back to `text` - Meta would reject that free-text send anyway
     * (the whole problem this exists to fix), and a silent downgrade would be
     * indistinguishable from a real send until the operator went looking for
     * why nobody replied. So this refuses as explicitly as every other
     * unkeyed-optional integration in this codebase, naming exactly what is
     * missing: no template, a template not approved AT THE PROVIDER (never
     * grantable locally - see `Template::isSendable()` and T-31), or no
     * configured template language.
     */
    private function templateBody(Message $message): array|DeliveryResult
    {
        $template = $message->template;

        if ($template === null) {
            return DeliveryResult::rejected(
                'WhatsApp requires a pre-approved template for a send outside the 24-hour '
                .'customer-service window, and this message has no template attached.',
            );
        }

        if (! $template->isSendable()) {
            return DeliveryResult::rejected(sprintf(
                'Template "%s" cannot be used outside the 24-hour window: approval_status is "%s". '
                .'A WhatsApp template is approved by Meta, never locally (T-31).',
                $template->code,
                $template->approval_status,
            ));
        }

        $templateName = (string) ($template->provider_template_id ?? '');

        if ($templateName === '') {
            return DeliveryResult::rejected(sprintf(
                'Template "%s" has no Meta-registered template name (provider_template_id) to send '
                .'outside the 24-hour window.',
                $template->code,
            ));
        }

        $language = $this->settings->get('providers.whatsapp.template_language');

        if (! is_string($language) || $language === '') {
            return DeliveryResult::rejected(
                'WhatsApp template language is not configured (providers.whatsapp.template_language); '
                .'cannot send a template message outside the 24-hour window.',
            );
        }

        return [
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $this->componentsFor($template, $message->lead),
            ],
        ];
    }

    /**
     * `components[].parameters`, positionally filled from the template's own
     * `variables` list against the send path's variable resolver (FR-COMM-02).
     *
     * Deliberately not a second substitution engine: `variables` is already the
     * ordered list of placeholder names this template declares (the same list
     * `TemplateController::preview()` renders against), and
     * `OutboundMessageService::templateVariables()` is the one place those
     * names resolve to values. This only reshapes that same resolution into the
     * positional wire shape Meta's template API wants instead of substituting it
     * into text - reusing the preview mechanism rather than reinventing token
     * substitution.
     *
     * @return array<int, array<string, mixed>>
     */
    private function componentsFor(Template $template, ?Lead $lead): array
    {
        $names = (array) ($template->variables ?? []);

        if ($names === [] || $lead === null) {
            return [];
        }

        $values = $this->outbound->templateVariables($lead);

        $parameters = array_map(
            fn ($name) => ['type' => 'text', 'text' => (string) ($values[$name] ?? '')],
            $names,
        );

        return [['type' => 'body', 'parameters' => $parameters]];
    }
}
