<?php

namespace App\Services\Messaging;

use App\Enums\Channel;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Jobs\SendMessage;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Template;
use App\Services\Dnc\DncService;
use App\Services\Leads\LeadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one way an outbound message is created (FR-COMM-01/02/04, BR-DNC-01).
 *
 * Individual sends, campaign sends and any future automation all come through
 * here, so the suppression gate, the template render, the idempotency key and
 * the timeline entry cannot be skipped by one caller and not another - the same
 * argument that puts every lead creation through `LeadService`.
 */
class OutboundMessageService
{
    public function __construct(
        private readonly DncService $dnc,
        private readonly LeadService $leads,
    ) {}

    /**
     * Prepares a message and queues it.
     *
     * Returns the Message in every case, including refusal - a suppressed send
     * is recorded as `skipped` with a reason rather than vanishing (BR-DNC-05,
     * FR-COMM-04). The CALLER decides what a skip means: an individual send
     * surfaces it as an error, campaign dispatch counts it and moves on. That
     * split is why this returns rather than throws.
     *
     * @param  array<string, mixed>  $content  subject/body, or overrides for a template
     *
     * @throws ApiException when the lead has no usable address for the channel
     */
    public function queue(
        Lead $lead,
        Channel $channel,
        array $content = [],
        ?int $actorId = null,
        ?Template $template = null,
        ?int $campaignId = null,
    ): Message {
        $recipient = $this->recipientFor($lead, $channel);

        if ($recipient === null) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf('This lead has no %s address on record.', $channel->label()),
            );
        }

        $rendered = $this->render($lead, $content, $template);

        return DB::transaction(function () use (
            $lead, $channel, $recipient, $rendered, $actorId, $template, $campaignId
        ) {
            // Asked BEFORE anything is queued. It is asked again inside the job
            // at dispatch time, because a lead suppressed between queueing and
            // sending must still be blocked (BR-DNC-03).
            if (! $this->dnc->canContact($lead, $channel)) {
                return $this->record($lead, $channel, $recipient, $rendered, $actorId, $template, $campaignId, [
                    'status' => 'skipped',
                    'skip_reason' => 'suppressed',
                ]);
            }

            $message = $this->record($lead, $channel, $recipient, $rendered, $actorId, $template, $campaignId, [
                'status' => 'queued',
            ]);

            // FR-COMM-01: never inline in the HTTP request, whatever the size.
            // A provider timeout must not become the user's timeout.
            SendMessage::dispatch($message->id)->onQueue('messages');

            return $message;
        });
    }

    /**
     * Renders subject and body, substituting `{{ placeholder }}` tokens.
     *
     * Deliberately not Blade. A template body is operator-supplied text, and
     * rendering it as Blade would make the template editor a remote code
     * execution primitive for anyone holding `templates.manage` (SEC-IN-06).
     *
     * @param  array<string, mixed>  $content
     * @return array{subject: ?string, body: ?string}
     */
    public function render(Lead $lead, array $content = [], ?Template $template = null): array
    {
        $subject = $content['subject'] ?? $template?->subject;
        $body = $content['body'] ?? $template?->body;

        foreach ($this->templateVariables($lead) as $key => $value) {
            $token = '{{ '.$key.' }}';
            $loose = '{{'.$key.'}}';

            $subject = $subject === null ? null : str_replace([$token, $loose], (string) $value, $subject);
            $body = $body === null ? null : str_replace([$token, $loose], (string) $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * The values a template's `{{ token }}` placeholders resolve to (FR-COMM-02).
     *
     * Exposed separately from `render()` so `WhatsAppDriver` can reuse exactly
     * these values as ordered wire parameters for a provider template send
     * (FR-WA-01) - the values are the same, only what happens to them differs:
     * `render()` substitutes them into free text, the WhatsApp template payload
     * places them positionally in `components[].parameters`. One source of truth
     * for what a placeholder name resolves to, rather than a second lookup here
     * that can drift from the preview/send renderer.
     *
     * @return array<string, string>
     */
    public function templateVariables(Lead $lead): array
    {
        return [
            'lead_name' => (string) $lead->name,
            'lead_company' => (string) $lead->company,
            'lead_city' => (string) $lead->city,
            'organisation' => (string) config('app.name'),
        ];
    }

    /** The address this channel would actually use. */
    public function recipientFor(Lead $lead, Channel $channel): ?string
    {
        $value = match ($channel) {
            Channel::Email => $lead->email,
            default => $lead->phone_e164,
        };

        return $value === '' ? null : $value;
    }

    /**
     * @param  array{subject: ?string, body: ?string}  $rendered
     * @param  array<string, mixed>  $state
     */
    private function record(
        Lead $lead,
        Channel $channel,
        string $recipient,
        array $rendered,
        ?int $actorId,
        ?Template $template,
        ?int $campaignId,
        array $state,
    ): Message {
        $message = Message::create(array_merge([
            'tenant_id' => config('crm.default_tenant_id'),
            'lead_id' => $lead->id,
            'campaign_id' => $campaignId,
            'template_id' => $template?->id,
            'user_id' => $actorId,
            'channel' => $channel->value,
            'direction' => 'outbound',
            'recipient' => $recipient,
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            // Unique in the database. That constraint, not an application
            // check, is what makes a retried job unable to double-send
            // (ARCHITECTURE §6).
            'idempotency_key' => (string) Str::uuid(),
        ], $state));

        $this->leads->recordActivity(
            $lead,
            $actorId,
            'message_'.($state['status'] === 'skipped' ? 'skipped' : 'queued'),
            $state['status'] === 'skipped'
                ? sprintf('%s not sent - lead is suppressed', $channel->label())
                : sprintf('%s queued', $channel->label()),
            ['message_id' => $message->id, 'channel' => $channel->value],
        );

        return $message;
    }
}
