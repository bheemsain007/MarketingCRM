<?php

namespace App\Services\Templates;

use App\Enums\Channel;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Template;
use Illuminate\Support\Str;

/**
 * Template management (FR-COMM-02, T-31).
 *
 * The table and the model have existed since Phase 2 and messages have accepted
 * a `template_id` since Phase 13, but nothing could author a template. This is
 * that half, and it owns three rules that a controller must not be trusted to
 * repeat: what a template's `code` is, what `approval_status` means, and why
 * retiring one is never a delete.
 *
 * What is deliberately absent: rendering. A template is rendered by
 * `OutboundMessageService::render()` and nowhere else, because that renderer is
 * plain `str_replace` rather than Blade on purpose - a template body is
 * operator-supplied text, and a second renderer written here would be exactly
 * the place someone later "improves" it into a remote code execution primitive
 * (SEC-IN-06).
 */
class TemplateService
{
    /**
     * Channels whose templates must be registered with, and approved by, the
     * PROVIDER before a send may use them (see the templates migration).
     *
     * Kept here rather than on the Channel enum because this is a fact about
     * templates, not about the channel: WhatsApp accepts free-form replies
     * inside a customer service window without any approval at all.
     *
     * @var array<int, Channel>
     */
    private const PROVIDER_APPROVED_CHANNELS = [Channel::WhatsApp, Channel::Rcs];

    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $actorId = null): Template
    {
        $channel = $this->toChannel($data['channel']);

        /*
         * A supplied code is honoured or refused; an absent one is derived from
         * the name and made unique.
         *
         * The asymmetry is the point. A code the caller chose is an integration
         * handle they will reference from elsewhere, so quietly handing them a
         * different one would break the reference they are about to write - a
         * 409 is the only useful answer. A derived code was never chosen by
         * anybody, so a collision is not the caller's mistake to fix.
         */
        if (($data['code'] ?? '') !== '') {
            $this->guardUniqueCode($data['code']);
            $code = $data['code'];
        } else {
            $code = $this->deriveCode($data['name']);
        }

        return Template::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'name' => $data['name'],
            'code' => $code,
            'channel' => $channel->value,
            // Only email has a subject line. The request layer already refuses
            // one on any other channel; this is the second gate, for callers
            // that are not HTTP requests.
            'subject' => $channel === Channel::Email ? ($data['subject'] ?? null) : null,
            'body' => $data['body'],
            'variables' => $data['variables'] ?? null,
            'media' => $data['media'] ?? null,
            'provider' => $data['provider'] ?? null,
            'provider_template_id' => $data['provider_template_id'] ?? null,
            'approval_status' => $this->initialApprovalStatus($channel, $data['provider_template_id'] ?? null),
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(Template $template, array $data, ?int $actorId = null): Template
    {
        if (isset($data['code']) && $data['code'] !== $template->code) {
            $this->guardUniqueCode($data['code'], $template->id);
        }

        $previousChannel = $template->channel;
        $channel = array_key_exists('channel', $data) ? $this->toChannel($data['channel']) : $previousChannel;

        $attributes = array_intersect_key($data, array_flip([
            'name', 'code', 'body', 'variables', 'media',
            'provider', 'provider_template_id', 'is_active',
        ]));

        $attributes['channel'] = $channel->value;

        if ($channel === Channel::Email) {
            if (array_key_exists('subject', $data)) {
                $attributes['subject'] = $data['subject'];
            }
        } else {
            // Moving a template off email drops the subject rather than leaving
            // a value the send path would silently discard. Storing text that
            // can never be delivered is how an operator ends up believing an SMS
            // carried a headline.
            $attributes['subject'] = null;
        }

        $template->fill($attributes);

        if ($channel !== $previousChannel) {
            // Approval belongs to the channel it was granted for: a WhatsApp
            // approval says nothing about an SMS, and an email template that
            // becomes a WhatsApp one has not been anywhere near Meta.
            $template->approval_status = $this->initialApprovalStatus($channel, $template->provider_template_id);
            $template->rejection_reason = null;
        } elseif (
            $this->requiresProviderApproval($channel)
            && $template->approval_status === 'approved'
            && $template->isDirty(['body', 'subject', 'media', 'variables'])
        ) {
            /*
             * The provider approved the text it was shown. Editing that text
             * invalidates the approval, so the template drops back to draft and
             * must be re-registered.
             *
             * Without this, the edit box becomes a way to get arbitrary content
             * out under an approved template id - which is the abuse the
             * provider's review exists to stop, and which fails at their end
             * anyway, after the operator has been told it sent (T-31).
             */
            $template->approval_status = 'draft';
            $template->rejection_reason = null;
        }

        $template->updated_by = $actorId;
        $template->save();

        return $template->fresh();
    }

    /**
     * Retires a template. Deliberately NOT a delete - not even a soft one.
     *
     * `messages` and `campaigns` hold `template_id`, and every read of that
     * history eager-loads `template:id,name,code`. A soft delete would satisfy
     * the database while making that relation resolve to null, so a message sent
     * last month would suddenly display with no template at all - destroying
     * exactly the history this endpoint exists to protect. Clearing `is_active`
     * takes the template out of pickers and campaign targeting and leaves every
     * historical record whole.
     *
     * The `deleted_at` column stays available for a future hard-retirement path;
     * this API never puts a template into that state.
     */
    public function deactivate(Template $template): Template
    {
        $template->update(['is_active' => false]);

        return $template->fresh();
    }

    /**
     * Puts a retired template back into circulation.
     *
     * It also un-trashes, even though `deactivate()` never trashes: a template
     * soft-deleted by a data migration or by an older code path is otherwise
     * invisible to the API and unrecoverable through it, and its code still
     * occupies the unique index (see `existingWithCode`).
     */
    public function restore(Template $template): Template
    {
        if ($template->trashed()) {
            $template->restore();
        }

        $template->update(['is_active' => true]);

        return $template->fresh();
    }

    /**
     * The approval state a template starts in (T-31).
     *
     * Two unrelated things are called "approval" here, and conflating them is
     * what makes this worth a method.
     *
     * On email, SMS and voice there is no external approver at all. Leaving
     * those at `draft` would make `Template::isSendable()` false forever and no
     * locally authored template could ever be used, so they are approved the
     * moment they are saved - locally authored IS locally approved.
     *
     * WhatsApp and RCS templates are approved by the PROVIDER, and nothing this
     * API does can grant that. So it never writes `approved` for them: a
     * template the operator has already registered out of band (it carries a
     * `provider_template_id`) is recorded as `pending`, everything else as
     * `draft`. The sync that confirms a provider decision is the remaining half
     * of T-31; until it exists, refusing to fake the state is the only honest
     * option, because a template marked approved here but not at Meta fails at
     * send time - after the operator has been told it went.
     */
    private function initialApprovalStatus(Channel $channel, ?string $providerTemplateId): string
    {
        if (! $this->requiresProviderApproval($channel)) {
            return 'approved';
        }

        return ($providerTemplateId ?? '') !== '' ? 'pending' : 'draft';
    }

    private function requiresProviderApproval(Channel $channel): bool
    {
        return in_array($channel, self::PROVIDER_APPROVED_CHANNELS, true);
    }

    /**
     * Builds a code from the template's name, e.g. "Diwali Offer 2026" ->
     * DIWALI_OFFER_2026.
     *
     * Derived once, at creation, and never recomputed: a later rename must not
     * move the code, because campaigns and any external integration already
     * reference the old one. The name is a label, the code is an identifier, and
     * only one of them is safe to change.
     */
    private function deriveCode(string $name): string
    {
        $base = trim(preg_replace('/[^A-Z0-9_]/', '', Str::upper(Str::slug($name, '_'))) ?? '', '_');
        $base = substr($base, 0, 90);

        // A name made entirely of characters the slug drops - CJK, emoji - is
        // still a valid name and must still produce a usable code.
        if ($base === '') {
            $base = 'TEMPLATE';
        }

        $code = $base;
        $suffix = 1;

        while ($this->existingWithCode($code) !== null) {
            $code = $base.'_'.(++$suffix);
        }

        return $code;
    }

    /**
     * Uniqueness is enforced by the database index too; this exists so the
     * caller gets a 409 that names the offending record instead of a raw
     * constraint violation rendered as a 500.
     */
    private function guardUniqueCode(string $code, ?int $ignoreId = null): void
    {
        $existing = $this->existingWithCode($code, $ignoreId);

        if ($existing === null) {
            return;
        }

        throw new ApiException(
            ErrorCode::Conflict,
            $existing->is_active
                ? "A template with the code \"{$code}\" already exists."
                : "A retired template already uses the code \"{$code}\". Restore it instead of creating a duplicate.",
            context: [
                'existing_template_id' => $existing->id,
                'is_active' => (bool) $existing->is_active,
            ],
        );
    }

    private function existingWithCode(string $code, ?int $ignoreId = null): ?Template
    {
        /*
         * withTrashed(), because the unique index on (tenant_id, code) spans
         * soft-deleted rows: a code held by a trashed template is still taken at
         * the database level. Without this the check passes, the insert fails,
         * and the caller is told nothing they can act on.
         */
        return Template::withTrashed()
            ->where('tenant_id', config('crm.default_tenant_id'))
            ->where('code', $code)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->first();
    }

    private function toChannel(Channel|string $channel): Channel
    {
        return $channel instanceof Channel ? $channel : Channel::from($channel);
    }
}
