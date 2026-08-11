<?php

namespace App\Http\Requests\Calls;

use App\Enums\CallStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starts a call, or logs one that already happened.
 *
 * With no `status` this creates a dial intent and the outcome follows later
 * (FR-CALL-03). With a `status` it records both at once - the common case for
 * a telecaller writing up a call they made from their handset.
 */
class StoreCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;    // Route gate + policy check in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(CallStatus::values())],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'ended_at' => ['nullable', 'date'],

            // Required when the lead asked to be rung back, so the promise
            // lands in somebody's diary (BR-CALL-05).
            'callback_at' => [
                'nullable',
                'date',
                'after:now',
                Rule::requiredIf(fn () => $this->input('status') === CallStatus::CallBackRequested->value),
            ],

            'dial_source' => ['nullable', Rule::in(['manual', 'auto_dialer', 'ai', 'inbound'])],
            'external_call_id' => ['nullable', 'string', 'max:190'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'callback_at.required' => 'When should this lead be called back?',
            'callback_at.after' => 'A callback has to be scheduled in the future.',
            'status.in' => 'That is not a call outcome.',
        ];
    }

    public function outcome(): ?CallStatus
    {
        return CallStatus::tryFrom((string) $this->input('status'));
    }
}
