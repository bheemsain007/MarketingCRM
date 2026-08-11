<?php

namespace App\Http\Requests\Leads;

use App\Enums\LeadStatus;
use App\Enums\StatusSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadStatusRequest extends FormRequest
{
    /**
     * Authorisation is the route's `permission:leads.update` gate plus the
     * policy check in the controller, which needs the resolved lead.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // FR-STAT-01: only the 11 statuses, anything else is a 422. The
            // transition matrix is checked afterwards, in the service - "not a
            // status" and "not a legal move from here" are different failures
            // and deserve different messages.
            'status' => ['required', 'string', Rule::in(LeadStatus::values())],

            // Required for reopens; the service enforces that, since it also
            // applies to callers that never see this request.
            'reason' => ['nullable', 'string', 'max:255'],

            // What drove the change, for attribution (GLOSSARY §2.6).
            'source' => ['nullable', 'string', Rule::in(StatusSource::values())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in' => 'That is not a lead status.',
        ];
    }

    public function status(): LeadStatus
    {
        return LeadStatus::from($this->string('status')->toString());
    }

    public function source(): StatusSource
    {
        return StatusSource::tryFrom((string) $this->input('source')) ?? StatusSource::Manual;
    }
}
