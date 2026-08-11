<?php

namespace App\Http\Requests\Leads;

use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;    // Route gate + policy check in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Optional: this endpoint also serves a plain "update the quoted
            // value" edit, which is not a status change.
            'interest_status' => ['nullable', 'string', Rule::in(LeadStatus::values())],
            'note' => ['nullable', 'string', 'max:255'],

            'quoted_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
