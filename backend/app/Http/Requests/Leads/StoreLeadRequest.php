<?php

namespace App\Http\Requests\Leads;

use App\Models\Lead;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Lead::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'alt_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],

            // Products the lead is interested in, attached on create.
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],

            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],

            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Phone validity is checked here as well as in LeadService so the caller
     * gets a field-level 422 rather than a generic error - the service check
     * remains because imports and webhooks do not pass through this request.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('phone') && ! PhoneNumber::isValid($this->input('phone'))) {
                $validator->errors()->add('phone', 'Enter a valid 10-digit mobile number.');
            }

            if ($this->filled('alt_phone') && ! PhoneNumber::isValid($this->input('alt_phone'))) {
                $validator->errors()->add('alt_phone', 'Enter a valid 10-digit mobile number.');
            }
        });
    }
}
