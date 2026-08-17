<?php

namespace App\Http\Requests\Templates;

use App\Enums\Channel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTemplateRequest extends FormRequest
{
    use ChecksTemplateChannel;

    public function authorize(): bool
    {
        // Route middleware (permission:templates.manage) is the gate.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],

            // Optional: TemplateService derives one from the name when it is
            // absent, so a UI with a name field and no code field still works.
            'code' => ['nullable', 'string', 'max:100', 'regex:/^[A-Z0-9_]+$/'],

            'channel' => ['required', Rule::enum(Channel::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            // Same ceiling as a message body - a template that cannot be sent
            // in a message is not a template.
            'body' => ['required', 'string', 'max:100000'],

            // The placeholder names this template declares. Constrained to the
            // token shape the renderer substitutes, so a declared variable that
            // could never match is caught here rather than at send time.
            'variables' => ['nullable', 'array', 'max:50'],
            'variables.*' => ['string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],

            'media' => ['nullable', 'array', 'max:10'],

            'provider' => ['nullable', 'string', 'max:50'],
            'provider_template_id' => ['nullable', 'string', 'max:190'],

            'is_active' => ['boolean'],

            /*
             * Refused loudly rather than dropped silently (T-31). Approval is
             * derived from the channel: local channels are approved on save,
             * WhatsApp and RCS approval is the provider's to give. A caller who
             * tried to set it has a wrong mental model, and silently ignoring
             * the field would leave them with it.
             */
            'approval_status' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may contain only uppercase letters, numbers and underscores (e.g. DIWALI_OFFER).',
            'variables.*.regex' => 'A variable name may contain only lowercase letters, numbers and underscores (e.g. lead_name).',
            'approval_status.prohibited' => 'Approval status is not set by hand. Templates on local channels are approved on save; WhatsApp and RCS approval comes from the provider.',
            'rejection_reason.prohibited' => 'A rejection reason is recorded from the provider\'s decision, not supplied by the caller.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // `filled`, not `has`: an explicit `"code": null` means "derive one for
        // me", and normalising it to "" would fail the regex instead.
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->applyTemplateChannelRules(
                $validator,
                $this->input('channel'),
                $this->filled('subject'),
            );
        });
    }
}
