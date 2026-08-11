<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string'],
            // web | android - determines which work session is opened, so
            // desktop and mobile time are tracked separately.
            'source' => ['sometimes', 'string', 'in:web,android'],
        ];
    }
}
