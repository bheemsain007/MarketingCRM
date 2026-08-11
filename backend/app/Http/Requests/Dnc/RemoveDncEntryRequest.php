<?php

namespace App\Http\Requests\Dnc;

use Illuminate\Foundation\Http\FormRequest;

class RemoveDncEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // The route's permission middleware is the gate.
    }

    /**
     * The reason is required, not optional.
     *
     * BR-DNC-06 audits actor, reason and timestamp on removal. Actor and
     * timestamp the server knows; the reason only exists if the caller is made
     * to supply it, and "why was this person put back on the call list?" is the
     * one question a compliance review actually asks.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }
}
