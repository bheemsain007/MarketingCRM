<?php

namespace App\Http\Requests\Leads;

use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lead'));
    }

    /**
     * Note what is absent: status, temperature, score, is_suppressed and
     * assigned_to. Those change only through their own services and endpoints
     * (SEC-IN-06) - a lead cannot be converted, un-suppressed or reassigned by
     * a general edit.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `filled` alongside `sometimes`: the field may be omitted, but if
            // it is sent it must carry a value. Without it an empty string
            // passes `string` and blanks the lead's name outright.
            'name' => ['sometimes', 'filled', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'filled', 'string', 'max:30'],
            'alt_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('phone') && ! PhoneNumber::isValid($this->input('phone'))) {
                $validator->errors()->add('phone', 'Enter a valid 10-digit mobile number.');
            }

            // Same check store() applies. Without it an unparseable alternate
            // number normalises to null and the edit appears to succeed while
            // quietly discarding what was typed.
            if ($this->filled('alt_phone') && ! PhoneNumber::isValid($this->input('alt_phone'))) {
                $validator->errors()->add('alt_phone', 'Enter a valid 10-digit mobile number.');
            }
        });
    }
}
