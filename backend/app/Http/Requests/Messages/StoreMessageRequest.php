<?php

namespace App\Http\Requests\Messages;

use App\Enums\Channel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // The route's permission middleware and the policy are the gates.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(Channel::class)],

            // Either a template or a body, checked below.
            'template_id' => ['nullable', 'integer', 'exists:templates,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('template_id') && ! $this->filled('body')) {
                $validator->errors()->add('body', 'Provide a body or choose a template.');
            }

            // Email is the only channel with a subject line; accepting one for
            // SMS would silently discard it at send time.
            if ($this->input('channel') !== Channel::Email->value && $this->filled('subject')) {
                $validator->errors()->add('subject', 'Only email messages carry a subject.');
            }
        });
    }
}
