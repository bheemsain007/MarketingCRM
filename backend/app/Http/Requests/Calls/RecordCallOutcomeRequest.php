<?php

namespace App\Http\Requests\Calls;

use App\Enums\CallStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What happened on a call that was already dialled (FR-CALL-02).
 *
 * Sent by the Android app after the handset finishes, or by a telecaller
 * writing up a call from the web (ADR-B).
 */
class RecordCallOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;    // Route gate + policy check in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // FR-CALL-01: the eleven, and nothing else.
            'status' => ['required', 'string', Rule::in(CallStatus::values())],

            'notes' => ['nullable', 'string', 'max:5000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'ended_at' => ['nullable', 'date'],

            'callback_at' => [
                'nullable',
                'date',
                'after:now',
                Rule::requiredIf(fn () => $this->input('status') === CallStatus::CallBackRequested->value),
            ],

            'external_call_id' => ['nullable', 'string', 'max:190'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'callback_at.required' => 'When should this lead be called back?',
            'status.in' => 'That is not a call outcome.',
        ];
    }

    public function outcome(): CallStatus
    {
        return CallStatus::from($this->string('status')->toString());
    }
}
