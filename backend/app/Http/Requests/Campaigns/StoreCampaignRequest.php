<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\Channel;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware (permission:campaigns.manage) is the gate.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],

            /*
             * Calling channels are excluded. A "campaign" that dials people is
             * the auto dialer, which has its own consent, calling-hours and
             * single-assignment rules (BR-CALL-02/03/04) that a bulk send
             * knows nothing about.
             */
            'channel' => ['required', Rule::in(array_map(
                fn (Channel $channel) => $channel->value,
                array_filter(
                    Channel::cases(),
                    fn (Channel $channel) => $channel !== Channel::Call && $channel !== Channel::AiCall,
                ),
            ))],

            'template_id' => ['nullable', 'integer', 'exists:templates,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],

            // A future time only. Scheduling into the past would either fire
            // immediately or never, and neither is what was meant.
            'scheduled_at' => ['nullable', 'date', 'after:now'],

            // A closed set of filters: audience_filters is operator input, and
            // anything more general would be an arbitrary query over leads.
            'audience_filters' => ['nullable', 'array'],
            'audience_filters.status' => ['nullable', 'array'],
            'audience_filters.status.*' => [Rule::in(LeadStatus::values())],
            'audience_filters.temperature' => ['nullable', 'array'],
            'audience_filters.temperature.*' => [Rule::in(array_map(
                fn (LeadTemperature $temperature) => $temperature->value,
                LeadTemperature::cases(),
            ))],
            'audience_filters.source' => ['nullable', 'array'],
            'audience_filters.source.*' => ['string', 'max:50'],
            'audience_filters.city' => ['nullable', 'array'],
            'audience_filters.city.*' => ['string', 'max:100'],
            'audience_filters.assigned_to' => ['nullable', 'array'],
            'audience_filters.assigned_to.*' => ['integer', 'exists:users,id'],
            'audience_filters.product_id' => ['nullable', 'array'],
            'audience_filters.product_id.*' => ['integer', 'exists:products,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'channel.in' => 'Campaigns cannot dial. Use the auto dialer for calling.',
            'scheduled_at.after' => 'A campaign can only be scheduled for a future time.',
        ];
    }
}
