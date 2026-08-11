<?php

namespace Tests\Feature\Meta;

use App\Enums\RoleName;
use App\Jobs\ProcessMetaLead;
use App\Models\Lead;
use App\Models\ProviderWebhookLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Meta\MetaLeadService;
use App\Services\Settings\SettingsService;
use Database\Seeders\LeadSourceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Facebook / Instagram Lead Ads capture (Phase 12, FR-META-01..03, SEC-WH-01..05).
 *
 * The signature and idempotency tests are the load-bearing ones. This endpoint
 * is open to the internet and creates records; without them, anyone who finds
 * the URL can inject leads into a paying customer's CRM, and a single Meta
 * retry can double the lead count.
 */
class MetaLeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'meta-app-secret-value';

    private const VERIFY_TOKEN = 'meta-verify-token-value';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LeadSourceSeeder::class);
        Http::preventStrayRequests();
    }

    private function configureMeta(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('providers.meta.app_secret', self::APP_SECRET);
        $settings->set('providers.meta.verify_token', self::VERIFY_TOKEN);
        $settings->set('providers.meta.page_access_token', 'page-token-value');
    }

    /** @return array<string, mixed> */
    private function payload(string $leadgenId = '1001', string $platform = 'facebook'): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => '77',
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => [
                        'leadgen_id' => $leadgenId,
                        'form_id' => '555',
                        'page_id' => '77',
                        'platform' => $platform,
                    ],
                ]],
            ]],
        ];
    }

    /** Signs exactly as Meta does: HMAC-SHA256 over the raw body. */
    private function deliver(array $payload, ?string $secret = self::APP_SECRET)
    {
        $raw = json_encode($payload);

        // Server vars rather than withHeaders(): `call()` does not apply the
        // default header bag, and the signature has to be computed over this
        // exact byte sequence.
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', '/api/v1/webhooks/meta', [], [], [], $server, $raw);
    }

    private function fakeGraph(array $fieldData): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['field_data' => $fieldData], 200)]);
    }

    // -----------------------------------------------------------------------
    // Subscription verification (SEC-WH-02)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_subscription_challenge_is_echoed_back_as_bare_text(): void
    {
        $this->configureMeta();

        // Not JSON and not the envelope - Meta rejects the subscription if the
        // body is anything but the challenge itself.
        $this->get('/api/v1/webhooks/meta?hub_mode=subscribe&hub_verify_token='
            .self::VERIFY_TOKEN.'&hub_challenge=abc123')
            ->assertOk()
            ->assertSee('abc123');
    }

    #[Test]
    public function a_wrong_verify_token_does_not_complete_the_subscription(): void
    {
        $this->configureMeta();

        $this->get('/api/v1/webhooks/meta?hub_mode=subscribe&hub_verify_token=guess&hub_challenge=abc123')
            ->assertStatus(403);
    }

    #[Test]
    public function verification_fails_closed_when_no_token_is_configured(): void
    {
        $this->get('/api/v1/webhooks/meta?hub_mode=subscribe&hub_verify_token=&hub_challenge=abc123')
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Signature (FR-META-02, SEC-WH-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function an_unsigned_delivery_is_rejected_before_anything_is_created(): void
    {
        Queue::fake();
        $this->configureMeta();

        $this->deliver($this->payload(), secret: null)->assertStatus(401);

        $this->assertDatabaseCount('leads', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_forged_signature_is_rejected(): void
    {
        Queue::fake();
        $this->configureMeta();

        // Anyone can POST here. The HMAC is the entire protection.
        $this->deliver($this->payload(), secret: 'wrong-secret')->assertStatus(401);

        $this->assertDatabaseCount('leads', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_unconfigured_endpoint_rejects_everything(): void
    {
        Queue::fake();

        // No app secret set. Accepting anything here would make a fresh install
        // an open lead-injection endpoint.
        $this->deliver($this->payload())->assertStatus(401);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_rejected_delivery_is_logged_without_storing_the_forged_body(): void
    {
        $this->configureMeta();

        $this->deliver(array_merge($this->payload(), [
            'attacker_note' => 'drop table leads',
        ]), secret: 'wrong')->assertStatus(401);

        // Stored for audit (SEC-WH-04), but a forged payload is
        // attacker-controlled content we are about to keep indefinitely.
        $log = ProviderWebhookLog::firstOrFail();
        $this->assertFalse($log->signature_valid);
        $this->assertStringNotContainsString('drop table', json_encode($log->payload));
    }

    // -----------------------------------------------------------------------
    // Idempotency (FR-META-03, SEC-WH-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_replayed_delivery_does_not_create_a_second_lead(): void
    {
        Queue::fake();
        $this->configureMeta();

        $this->deliver($this->payload('2002'))->assertOk();
        $this->deliver($this->payload('2002'))->assertOk();

        // Enforced by the unique index, not by a check a race could defeat.
        $this->assertSame(1, ProviderWebhookLog::where('provider', 'meta')->count());
        Queue::assertPushed(ProcessMetaLead::class, 1);
    }

    #[Test]
    public function a_delivery_mixing_a_new_lead_with_a_repeat_processes_only_the_new_one(): void
    {
        Queue::fake();
        $this->configureMeta();

        $this->deliver($this->payload('3003'))->assertOk();

        // One envelope, two leadgens - one already seen. Keying the log on the
        // leadgen rather than the delivery is what makes this work.
        $mixed = $this->payload('3003');
        $mixed['entry'][0]['changes'][] = [
            'field' => 'leadgen',
            'value' => ['leadgen_id' => '4004', 'form_id' => '555', 'page_id' => '77'],
        ];

        $this->deliver($mixed)->assertOk();

        $this->assertSame(2, ProviderWebhookLog::where('provider', 'meta')->count());
        Queue::assertPushed(ProcessMetaLead::class, 2);
    }

    #[Test]
    public function a_subscription_to_another_field_is_ignored(): void
    {
        Queue::fake();
        $this->configureMeta();

        $this->deliver([
            'object' => 'page',
            'entry' => [['id' => '77', 'changes' => [['field' => 'feed', 'value' => ['post_id' => 'x']]]]],
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------------
    // The endpoint does no privileged work inline (SEC-WH-05)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_webhook_only_enqueues_and_never_creates_the_lead_itself(): void
    {
        Queue::fake();
        $this->configureMeta();

        $this->deliver($this->payload())->assertOk();

        // Graph is never called from the request cycle - preventStrayRequests
        // would fail this test if it were.
        $this->assertDatabaseCount('leads', 0);
        Queue::assertPushed(ProcessMetaLead::class);
    }

    // -----------------------------------------------------------------------
    // End-to-end lead creation (FR-META-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lead_is_created_from_the_graph_response_and_auto_assigned(): void
    {
        $this->configureMeta();

        // Someone eligible to receive it (BR-ASSIGN-02).
        $telecaller = User::factory()->create();
        $telecaller->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());

        $this->fakeGraph([
            ['name' => 'full_name', 'values' => ['Ramesh Kumar']],
            ['name' => 'phone_number', 'values' => ['+91 98765-43210']],
            ['name' => 'email', 'values' => ['ramesh@example.com']],
            ['name' => 'city', 'values' => ['Jaipur']],
        ]);

        Queue::fake();
        $this->deliver($this->payload('5005'))->assertOk();

        $log = ProviderWebhookLog::firstOrFail();
        (new ProcessMetaLead($log->id))->handle(app(MetaLeadService::class));

        $lead = Lead::firstOrFail();
        $this->assertSame('Ramesh Kumar', $lead->name);
        // Normalised on the way in, whatever Meta handed us (BR-DUP-01).
        $this->assertSame('+919876543210', $lead->phone_e164);
        $this->assertSame('Jaipur', $lead->city);

        // FR-LEAD-10: nobody watches a queue at 2am.
        $this->assertSame($telecaller->id, $lead->assigned_to);

        $this->assertDatabaseHas('lead_activities', ['activity_type' => 'meta_lead_captured']);
    }

    #[Test]
    public function an_instagram_lead_is_attributed_to_instagram(): void
    {
        $this->configureMeta();
        $this->fakeGraph([
            ['name' => 'full_name', 'values' => ['Priya S']],
            ['name' => 'phone_number', 'values' => ['9876500011']],
        ]);

        Queue::fake();
        $this->deliver($this->payload('6006', platform: 'instagram'))->assertOk();
        (new ProcessMetaLead(ProviderWebhookLog::firstOrFail()->id))->handle(app(MetaLeadService::class));

        // Source attribution is what makes campaign ROI reportable at all -
        // lumping Instagram in with Facebook would hide which one pays.
        $this->assertSame('INSTAGRAM_ADS', Lead::firstOrFail()->source->code);
    }

    #[Test]
    public function a_repeat_enquiry_updates_the_existing_lead_instead_of_duplicating(): void
    {
        $this->configureMeta();
        $existing = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->fakeGraph([
            ['name' => 'full_name', 'values' => ['Ramesh Kumar']],
            ['name' => 'phone_number', 'values' => ['9876543210']],
        ]);

        Queue::fake();
        $this->deliver($this->payload('7007'))->assertOk();
        (new ProcessMetaLead(ProviderWebhookLog::firstOrFail()->id))->handle(app(MetaLeadService::class));

        // BR-DUP-02: never silently a second lead. The existing owner gets a
        // timeline entry so the new enquiry is not invisible to them.
        $this->assertSame(1, Lead::count());
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $existing->id,
            'activity_type' => 'meta_lead_repeat',
        ]);
    }

    #[Test]
    public function custom_form_answers_are_kept_as_a_note_rather_than_dropped(): void
    {
        $this->configureMeta();
        $this->fakeGraph([
            ['name' => 'full_name', 'values' => ['Anil']],
            ['name' => 'phone_number', 'values' => ['9876500022']],
            ['name' => 'what_is_your_budget', 'values' => ['Under 5 lakh']],
        ]);

        Queue::fake();
        $this->deliver($this->payload('8008'))->assertOk();
        (new ProcessMetaLead(ProviderWebhookLog::firstOrFail()->id))->handle(app(MetaLeadService::class));

        // Custom fields have nowhere to live until T-40. Dropping what the
        // prospect typed would be worse than an imperfect home for it.
        $this->assertStringContainsString('Under 5 lakh', Lead::firstOrFail()->notes()->first()->body);
    }

    #[Test]
    public function a_lead_with_no_contact_detail_is_not_created(): void
    {
        $this->configureMeta();
        $this->fakeGraph([['name' => 'full_name', 'values' => ['Nobody']]]);

        Queue::fake();
        $this->deliver($this->payload('9009'))->assertOk();

        $log = ProviderWebhookLog::firstOrFail();
        (new ProcessMetaLead($log->id))->failed(
            new \RuntimeException('The Meta lead carried neither a phone number nor an email address.'),
        );

        // Neither a phone nor an email is not a lead anyone can act on.
        $this->assertDatabaseCount('leads', 0);
        $this->assertNotNull($log->fresh()->processing_error);
    }

    #[Test]
    public function a_failure_is_recorded_against_the_delivery_rather_than_vanishing(): void
    {
        $this->configureMeta();

        Queue::fake();
        $this->deliver($this->payload('1010'))->assertOk();
        $log = ProviderWebhookLog::firstOrFail();

        (new ProcessMetaLead($log->id))->failed(new \RuntimeException('Graph API returned 500.'));

        // A lead that never arrived is a paid click wasted; somebody has to be
        // able to find out why.
        $log->refresh();
        $this->assertNotNull($log->processed_at);
        $this->assertStringContainsString('Graph API returned 500', (string) $log->processing_error);
    }

    #[Test]
    public function capture_cannot_work_without_a_page_access_token(): void
    {
        // Signature configured, token not.
        $settings = app(SettingsService::class);
        $settings->set('providers.meta.app_secret', self::APP_SECRET);

        Queue::fake();
        $this->deliver($this->payload('1111'))->assertOk();

        // Unlike the outbound channels, inbound capture genuinely cannot run
        // unkeyed: the webhook carries an id, not the lead.
        $this->expectException(\RuntimeException::class);
        (new ProcessMetaLead(ProviderWebhookLog::firstOrFail()->id))->handle(app(MetaLeadService::class));
    }
}
