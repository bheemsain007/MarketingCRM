<?php

namespace App\Http\Requests\Campaigns;

/**
 * Same shape as creation, with everything optional.
 *
 * Whether the campaign may be edited at all is CampaignService's call, not a
 * validation rule: it depends on the campaign's current state, and the answer
 * ("clone it instead") is a business message rather than a field error.
 */
class UpdateCampaignRequest extends StoreCampaignRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = ['sometimes', 'string', 'max:190'];
        $rules['channel'][0] = 'sometimes';

        return $rules;
    }
}
