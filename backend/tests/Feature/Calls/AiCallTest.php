<?php

namespace Tests\Feature\Calls;

use App\Enums\CallStatus;
use App\Enums\DncReason;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AI calling through Vaaad (Phase 24/25, FR-AI-01, BR-INT-04).
 *
 * Optional by design: with no key the feature refuses clearly rather than
 * half-working. When keyed it dials through the same gate as a human call, and
 * the result webhook feeds the interest engine - which, not this code, decides
 * whether a confidence score is high enough to move the lead (BR-INT-04).
 */
class AiCallTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'vaaad-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Http::preventStrayRequests();

        /*
         * Frozen inside calling hours, as AutoDialerTest does.
         *
         * An AI call goes through the same BR-CALL-04 gate as a human one, so
         * without this the suite passes during the working day and fails after
         * 20:00 - which is exactly when someone runs it before going home, and
         * exactly the kind of failure that gets dismissed as "flaky" rather
         * than read.
         */
        Carbon::setTestNow(Carbon::parse('2026-08-10 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());
        $this->actingAs($user->fresh(), 'sanctum');

        return $user->fresh();
    }

    private function configureVaaad(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('providers.vaaad.api_key', 'vaaad-key-123');
        $settings->set('providers.vaaad.webhook_secret', self::SECRET);
    }

    // -----------------------------------------------------------------------
    // Phase 24 - placing the call (optional credential)
    // -----------------------------------------------------------------------

    #[Test]
    public function unkeyed_ai_calling_is_refused_with_a_clear_message(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        // No key configured, and preventStrayRequests guarantees no call was
        // attempted - the feature is off, not half-on.
        $this->postJson("/api/v1/leads/{$lead->id}/ai-call")
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'not configured'));

        $this->assertDatabaseCount('calls', 0);
    }

    #[Test]
    public function a_configured_ai_call_is_placed_and_recorded(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $this->configureVaaad();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['api.vaaad.ai/*' => Http::response(['call_id' => 'vaaad-777'], 200)]);

        $this->postJson("/api/v1/leads/{$lead->id}/ai-call", ['script' => 'Greet and qualify.'])
            ->assertStatus(202)
            ->assertJsonPath('data.dial_source', 'ai');

        // Recorded with no status yet - the outcome arrives later by webhook,
        // exactly like a human dial intent.
        $this->assertDatabaseHas('calls', [
            'lead_id' => $lead->id,
            'dial_source' => 'ai',
            'external_call_id' => 'vaaad-777',
            'status' => null,
        ]);

        Http::assertSent(fn ($request) => $request['to'] === '+919876543210');
    }

    #[Test]
    public function a_suppressed_lead_is_refused_before_any_provider_request(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $this->configureVaaad();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();

        // The gate runs before Vaaad is called: if it did not, preventStrayRequests
        // would fail this test instead of the clean 403.
        $this->postJson("/api/v1/leads/{$lead->id}/ai-call")->assertStatus(403);

        $this->assertDatabaseCount('calls', 0);
    }

    #[Test]
    public function a_role_without_calls_create_cannot_place_an_ai_call(): void
    {
        // Accounts is read-only on leads and holds no calls.create.
        $this->actingAsRole(RoleName::Accounts);
        $this->configureVaaad();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->postJson("/api/v1/leads/{$lead->id}/ai-call")->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Phase 25 - ingesting the result (BR-INT-04)
    // -----------------------------------------------------------------------

    private function aiCall(Lead $lead, string $externalId = 'vaaad-777'): Call
    {
        return Call::factory()->for($lead)->create([
            'dial_source' => 'ai',
            'external_call_id' => $externalId,
            'status' => null,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function postWebhook(array $body, bool $signed = true)
    {
        return $this->withHeaders($signed ? ['X-Webhook-Token' => self::SECRET] : [])
            ->postJson('/api/v1/webhooks/vaaad', $body);
    }

    #[Test]
    public function the_result_webhook_records_the_call_outcome(): void
    {
        $this->configureVaaad();
        $lead = Lead::factory()->create();
        $call = $this->aiCall($lead);

        $this->postWebhook([
            'call_id' => 'vaaad-777',
            'outcome' => 'connected',
            'duration_seconds' => 142,
        ])->assertOk()->assertJsonPath('message', 'Processed.');

        $call->refresh();
        $this->assertSame(CallStatus::Connected, $call->status);
        $this->assertSame(142, $call->duration_seconds);
    }

    #[Test]
    public function a_high_confidence_signal_moves_the_lead(): void
    {
        $this->configureVaaad();
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);
        $this->aiCall($lead);

        $this->postWebhook([
            'call_id' => 'vaaad-777',
            'outcome' => 'connected',
            'interest_confidence' => 0.9,
            'summary' => 'Asked about pricing.',
        ])->assertOk();

        // Recorded and acted on: above the 0.75 threshold, so it counts.
        $this->assertDatabaseHas('interest_signals', [
            'lead_id' => $lead->id,
            'type' => 'ai_interest_detected',
            'acted_on' => true,
        ]);
        $this->assertNotSame(LeadStatus::New->value, $lead->fresh()->status->value);
    }

    #[Test]
    public function a_low_confidence_signal_is_recorded_but_does_not_move_the_lead(): void
    {
        $this->configureVaaad();
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);
        $this->aiCall($lead);

        $this->postWebhook([
            'call_id' => 'vaaad-777',
            'outcome' => 'connected',
            'interest_confidence' => 0.3,
            'summary' => 'Unclear - maybe interested.',
        ])->assertOk();

        // BR-INT-04: below the threshold the evidence is KEPT, but it does not
        // reclassify the lead on a guess.
        $this->assertDatabaseHas('interest_signals', [
            'lead_id' => $lead->id,
            'type' => 'ai_interest_detected',
            'acted_on' => false,
        ]);
        $this->assertSame(LeadStatus::New->value, $lead->fresh()->status->value);
    }

    #[Test]
    public function the_webhook_rejects_a_missing_secret(): void
    {
        $this->configureVaaad();
        $lead = Lead::factory()->create();
        $call = $this->aiCall($lead);

        $this->postWebhook(['call_id' => 'vaaad-777', 'outcome' => 'connected'], signed: false)
            ->assertStatus(401);

        // The forged result must not have written an outcome.
        $this->assertNull($call->fresh()->status);
    }

    #[Test]
    public function an_unknown_call_id_is_acknowledged_without_error(): void
    {
        $this->configureVaaad();

        $this->postWebhook(['call_id' => 'nope', 'outcome' => 'connected'])
            ->assertOk()
            ->assertJsonPath('message', 'No matching call.');
    }
}
