<?php

namespace Tests\Feature\Dnc;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\Dnc\DncService;
use App\Services\Dnc\SuppressionMatrix;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Runtime overrides of the DNC matrix (BR-DNC-02/04, T-65).
 *
 * BR-DNC-04 asks for the matrix to be configuration rather than code. It is
 * honoured in part, on purpose: the reasons that record a person's explicit
 * instruction stay absolute in code, and only the ones that are our own
 * inference from an outcome can be tuned.
 *
 * The load-bearing tests here are the negative ones - that no settings write
 * can unblock a lead who opted out, and that a malformed override widens back
 * to the built-in list rather than quietly narrowing suppression.
 */
class SuppressionMatrixOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function override(DncReason $reason, string $channels): void
    {
        app(SettingsService::class)->set(SuppressionMatrix::SETTING_PREFIX.$reason->value, $channels);
    }

    private function suppressedLead(DncReason $reason): Lead
    {
        $lead = Lead::factory()->create();
        app(DncService::class)->suppress($lead, $reason, channel: null, source: 'manual');

        return $lead->fresh();
    }

    // -----------------------------------------------------------------------
    // The tunable half
    // -----------------------------------------------------------------------

    #[Test]
    public function not_interested_can_be_narrowed_to_stop_blocking_manual_calls(): void
    {
        // BR-DNC-04's own example: whether "Not Interested" blocks a human
        // picking up the phone must be changeable without a deploy.
        $lead = $this->suppressedLead(DncReason::NotInterested);
        $dnc = app(DncService::class);

        $this->assertFalse($dnc->canContact($lead, Channel::Call));

        $this->override(DncReason::NotInterested, 'sms,whatsapp,rcs,voice,email');

        $this->assertTrue($dnc->canContact($lead, Channel::Call));
        $this->assertFalse($dnc->canContact($lead, Channel::Sms));
    }

    #[Test]
    public function a_reason_can_be_widened_too(): void
    {
        $lead = $this->suppressedLead(DncReason::BouncedEmail);
        $dnc = app(DncService::class);

        // A bounce says nothing about the phone by default.
        $this->assertTrue($dnc->canContact($lead, Channel::Call));

        $this->override(DncReason::BouncedEmail, 'email,sms');

        $this->assertFalse($dnc->canContact($lead, Channel::Sms));
    }

    // -----------------------------------------------------------------------
    // The half that must not move
    // -----------------------------------------------------------------------

    #[Test]
    public function no_setting_can_unblock_a_lead_who_opted_out(): void
    {
        $lead = $this->suppressedLead(DncReason::OptedOut);

        // Written directly, bypassing the settings API, because the API will
        // not accept the key at all - see the registry test below. Even with
        // the row present, the matrix must ignore it.
        \DB::table('settings')->insert([
            'tenant_id' => config('crm.default_tenant_id'),
            'key' => SuppressionMatrix::SETTING_PREFIX.DncReason::OptedOut->value,
            'value' => encrypt('email'),
            'is_secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(SettingsService::class)->flush();

        /*
         * "Stop contacting me" is not a toggle. One settings write, with no
         * code review anywhere in the path, must never be able to start the
         * dialer on somebody who opted out.
         */
        foreach (Channel::cases() as $channel) {
            $this->assertFalse(
                app(DncService::class)->canContact($lead, $channel),
                "opted out must still block {$channel->value}",
            );
        }
    }

    #[Test]
    public function the_absolute_reasons_are_not_even_offered_as_settings(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum');

        $response = $this->getJson('/api/v1/settings')->assertOk();
        $keys = json_encode($response->json());

        $this->assertStringNotContainsString(
            SuppressionMatrix::SETTING_PREFIX.DncReason::DoNotContact->value,
            (string) $keys,
        );
        $this->assertStringNotContainsString(
            SuppressionMatrix::SETTING_PREFIX.DncReason::OptedOut->value,
            (string) $keys,
        );
        $this->assertStringContainsString(
            SuppressionMatrix::SETTING_PREFIX.DncReason::NotInterested->value,
            (string) $keys,
        );
    }

    #[Test]
    public function an_unsettable_key_is_refused_by_the_api(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum');

        // The registry is an allowlist, so an absolute reason cannot be
        // written even by somebody who knows the key name.
        $this->patchJson('/api/v1/settings', [
            'settings' => [SuppressionMatrix::SETTING_PREFIX.DncReason::OptedOut->value => 'email'],
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Malformed overrides
    // -----------------------------------------------------------------------

    #[Test]
    public function an_unknown_channel_falls_back_to_the_built_in_list(): void
    {
        $lead = $this->suppressedLead(DncReason::WrongNumber);

        // Bypassing validation on purpose: this is the state a hand-edited
        // database row or a future import would leave behind.
        \DB::table('settings')->insert([
            'tenant_id' => config('crm.default_tenant_id'),
            'key' => SuppressionMatrix::SETTING_PREFIX.DncReason::WrongNumber->value,
            'value' => encrypt('call,telepathy'),
            'is_secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(SettingsService::class)->flush();

        /*
         * Falling back to the parsed part would silently unblock SMS - the
         * channel whose name was mistyped. Suppression widens on error; it
         * never narrows.
         */
        $this->assertFalse(app(DncService::class)->canContact($lead, Channel::Sms));
    }

    #[Test]
    public function an_empty_override_restores_the_built_in_list(): void
    {
        $lead = $this->suppressedLead(DncReason::NotInterested);

        $this->override(DncReason::NotInterested, '');

        $this->assertFalse(app(DncService::class)->canContact($lead, Channel::Call));
    }

    #[Test]
    public function a_malformed_value_is_refused_at_the_boundary(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum');

        $this->patchJson('/api/v1/settings', [
            'settings' => [SuppressionMatrix::SETTING_PREFIX.DncReason::NotInterested->value => 'call,telepathy'],
        ])->assertStatus(422);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::SuperAdmin->value)->first());

        return $user->fresh();
    }
}
