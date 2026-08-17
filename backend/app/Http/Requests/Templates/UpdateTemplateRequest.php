<?php

namespace App\Http\Requests\Templates;

use App\Enums\Channel;
use App\Models\Template;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTemplateRequest extends FormRequest
{
    use ChecksTemplateChannel;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'code' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Z0-9_]+$/'],
            'channel' => ['sometimes', Rule::enum(Channel::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:100000'],

            'variables' => ['nullable', 'array', 'max:50'],
            'variables.*' => ['string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],

            'media' => ['nullable', 'array', 'max:10'],

            'provider' => ['nullable', 'string', 'max:50'],
            'provider_template_id' => ['nullable', 'string', 'max:190'],

            'is_active' => ['boolean'],

            // See StoreTemplateRequest - approval is derived, never dictated
            // (T-31).
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
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /*
             * The channel rules run against the EFFECTIVE channel, not the
             * submitted one. A PATCH that sends only a subject must still be
             * judged against the channel the template already has, or an SMS
             * template could be given a subject one field at a time.
             */
            $template = $this->route('template');

            $channel = $this->input('channel')
                ?? ($template instanceof Template ? $template->channel->value : null);

            $this->applyTemplateChannelRules($validator, $channel, $this->filled('subject'));
        });
    }
}
