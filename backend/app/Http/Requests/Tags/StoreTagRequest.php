<?php

namespace App\Http\Requests\Tags;

use Illuminate\Foundation\Http\FormRequest;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware (permission:settings.manage) and TagPolicy are the
        // gates.
        return true;
    }

    /**
     * `is_system` is deliberately absent, and must stay absent.
     *
     * It marks a tag the Interest Engine owns and that nobody may rename or
     * delete (BR-INT-02). Accepting it from a request body would let anyone
     * with settings.manage mint a tag that not even a Super Admin could remove.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 60 is the column width. The slug is derived from the name, so a
            // name carrying no letter or digit would slug to an empty string
            // and collide on the (tenant_id, slug) unique index.
            'name' => ['required', 'string', 'max:60', 'regex:/[\pL\pN]/u'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name must contain at least one letter or number.',
            'color.regex' => 'The colour must be a six-digit hex value, e.g. #6B7280.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}
