<?php

namespace App\Http\Requests\Leads;

use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;    // Route gate + policy check in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],

            // Where this interest starts. Defaults to New; a telecaller who
            // has already had the conversation can open it further along.
            'interest_status' => ['nullable', 'string', Rule::in(LeadStatus::values())],

            'quoted_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
