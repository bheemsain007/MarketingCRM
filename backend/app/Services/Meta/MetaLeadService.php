<?php

namespace App\Services\Meta;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\Leads\LeadService;
use App\Services\Settings\SettingsService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Turns a Meta leadgen notification into a lead (FR-META-01).
 *
 * **A Meta webhook does not contain the lead.** It carries a `leadgen_id` and
 * nothing else useful, so the answers have to be fetched from the Graph API
 * with a page access token. That is why - unlike the outbound channels, which
 * run end to end against a log driver - inbound capture genuinely cannot work
 * unkeyed. Without a token there is nothing to create a lead from.
 *
 * Creation goes through `LeadService` like every other entry path, so phone
 * normalisation, duplicate detection and the timeline entry are the same ones
 * manual entry and CSV import get (BR-DUP-01/02).
 */
class MetaLeadService
{
    private const GRAPH_VERSION = 'v21.0';

    /**
     * Meta's standard field names mapped to ours. Anything not listed is a
     * custom question on the form.
     */
    private const FIELD_MAP = [
        'full_name' => 'name',
        'email' => 'email',
        'phone_number' => 'phone',
        'city' => 'city',
        'state' => 'state',
        'company_name' => 'company',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly LeadService $leads,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured('providers.meta.page_access_token');
    }

    /**
     * @param  array<string, mixed>  $event  the stored leadgen payload
     *
     * @throws RuntimeException when the lead cannot be fetched - the job
     *                          retries, because a Graph outage is transient
     */
    public function import(array $event): Lead
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'No Meta page access token is configured, so the lead cannot be fetched.',
            );
        }

        $fields = $this->fetch((string) $event['leadgen_id']);
        $mapped = $this->map($fields);

        if (($mapped['phone'] ?? null) === null && ($mapped['email'] ?? null) === null) {
            // Neither a phone nor an email is not a lead we can ever act on.
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'The Meta lead carried neither a phone number nor an email address.',
            );
        }

        // BR-DUP-02: a repeat enquiry from the same person is not a second
        // lead. The existing record gets a timeline entry so the new enquiry is
        // still visible to whoever owns the relationship (BR-ASSIGN-05).
        if (($mapped['phone'] ?? null) !== null) {
            $existing = $this->leads->findByPhone($mapped['phone']);

            if ($existing !== null) {
                $this->leads->recordActivity(
                    $existing,
                    null,
                    'meta_lead_repeat',
                    'Enquired again through '.$this->platformLabel($event),
                    ['leadgen_id' => $event['leadgen_id'], 'form_id' => $event['form_id'] ?? null],
                );

                return $existing;
            }
        }

        $lead = $this->leads->create(
            array_merge($mapped, [
                'lead_source_id' => $this->sourceIdFor($event),
            ]),
            actorId: null,
            // FR-LEAD-10: an inbound lead is assigned automatically. Nobody is
            // watching a queue at 2am, and an unassigned inbound lead is one
            // nobody calls.
            autoAssign: true,
        );

        $this->leads->recordActivity(
            $lead,
            null,
            'meta_lead_captured',
            'Captured from '.$this->platformLabel($event),
            [
                'leadgen_id' => $event['leadgen_id'],
                'form_id' => $event['form_id'] ?? null,
                'ad_id' => $event['ad_id'] ?? null,
            ],
        );

        // Custom form questions have nowhere to live until lead custom fields
        // exist (T-40), and dropping what the prospect typed would be worse
        // than putting it somewhere imperfect.
        if ($extra = $this->unmappedAnswers($fields)) {
            $lead->notes()->create([
                'user_id' => null,
                'body' => "Answers from the Meta form:\n".$extra,
            ]);
        }

        return $lead;
    }

    /**
     * @return array<int, array<string, mixed>> Meta's `field_data`
     */
    private function fetch(string $leadgenId): array
    {
        $response = Http::timeout(15)->get(
            sprintf('https://graph.facebook.com/%s/%s', self::GRAPH_VERSION, $leadgenId),
            [
                'access_token' => $this->settings->get('providers.meta.page_access_token'),
                'fields' => 'id,created_time,field_data,form_id,ad_id,platform',
            ],
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                'Graph API returned '.$response->status().' for leadgen '.$leadgenId.'.',
            );
        }

        return (array) $response->json('field_data', []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function map(array $fields): array
    {
        $mapped = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $value = trim((string) (($field['values'] ?? [])[0] ?? ''));

            if ($value === '' || ! isset(self::FIELD_MAP[$name])) {
                continue;
            }

            $mapped[self::FIELD_MAP[$name]] = $value;
        }

        // Meta hands back numbers in whatever the user typed, frequently with a
        // country code and punctuation. Normalising here means the duplicate
        // check below compares like with like.
        if (isset($mapped['phone'])) {
            $mapped['phone'] = PhoneNumber::normalise($mapped['phone']);

            if ($mapped['phone'] === null) {
                unset($mapped['phone']);
            }
        }

        // Forms that only collect first/last name.
        if (! isset($mapped['name'])) {
            $mapped['name'] = $this->joinNames($fields) ?? 'Unnamed Meta lead';
        }

        return $mapped;
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function joinNames(array $fields): ?string
    {
        $parts = [];

        foreach ($fields as $field) {
            if (in_array($field['name'] ?? '', ['first_name', 'last_name'], true)) {
                $parts[$field['name']] = trim((string) (($field['values'] ?? [])[0] ?? ''));
            }
        }

        $joined = trim(($parts['first_name'] ?? '').' '.($parts['last_name'] ?? ''));

        return $joined === '' ? null : $joined;
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function unmappedAnswers(array $fields): string
    {
        $lines = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $value = trim((string) (($field['values'] ?? [])[0] ?? ''));

            if ($value === '' || isset(self::FIELD_MAP[$name]) || in_array($name, ['first_name', 'last_name'], true)) {
                continue;
            }

            $lines[] = '- '.str_replace('_', ' ', $name).': '.$value;
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $event */
    private function platformLabel(array $event): string
    {
        return ($event['platform'] ?? 'facebook') === 'instagram'
            ? 'Instagram Lead Ads'
            : 'Facebook Lead Ads';
    }

    /** @param array<string, mixed> $event */
    private function sourceIdFor(array $event): ?int
    {
        $code = ($event['platform'] ?? 'facebook') === 'instagram'
            ? 'INSTAGRAM_ADS'
            : 'FACEBOOK_ADS';

        return LeadSource::query()->where('code', $code)->value('id');
    }
}
