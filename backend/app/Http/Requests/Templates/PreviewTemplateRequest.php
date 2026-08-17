<?php

namespace App\Http\Requests\Templates;

use Illuminate\Foundation\Http\FormRequest;

class PreviewTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `templates.view` on the route is the coarse gate; the controller runs
        // LeadPolicy on the lead itself, which is the check that matters here.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A real lead, because the point of the preview is to see what will
            // actually be sent - a fabricated one would show placeholder text
            // that reads as a working render and is not one.
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
        ];
    }
}
