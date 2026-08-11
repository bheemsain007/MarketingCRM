<?php

namespace App\Http\Requests\Dnc;

use App\Enums\Channel;
use App\Enums\DncReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDncEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // The route's permission middleware is the gate.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
            'reason' => ['required', Rule::enum(DncReason::class)],

            // Omit for "everything this reason blocks" (BR-DNC-02). Setting it
            // narrows the entry to one channel - an SMS opt-out that leaves
            // email contactable.
            'channel' => ['nullable', Rule::enum(Channel::class)],

            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
