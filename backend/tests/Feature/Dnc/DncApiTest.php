<?php

namespace Tests\Feature\Dnc;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suppression administration (FR-DNC-01/04, BR-DNC-06).
 *
 * The Phase 19 admin surface, brought forward. What matters here is not CRUD -
 * it is that lifting a suppression is hard to do accidentally, impossible to do
 * without leaving a record, and impossible to do at all for the role most
 * motivated to do it.
 */
class DncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = $this->user($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // -----------------------------------------------------------------------
    // Listing and scope
    // -----------------------------------------------------------------------

    #[Test]
    public function a_manager_can_list_suppression_records(): void
    {
        $this->actingAsRole(RoleName::Admin);
        DncEntry::factory()->count(3)->create();

        $this->getJson('/api/v1/dnc')
            ->assertOk()
            ->assertJsonCount(3, 'data.items');
    }

    #[Test]
    public function a_telecaller_sees_suppression_only_for_their_own_leads(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        DncEntry::factory()->for(Lead::factory()->create(['assigned_to' => $me->id]))->create();
        DncEntry::factory()->for(Lead::factory()->create(['assigned_to' => $colleague->id]))->create();

        // The organisation's DNC list is a list of everyone who has ever
        // refused us. Scope it like any other lead data (SEC-AUTHZ-03).
        $this->getJson('/api/v1/dnc')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function an_entry_with_no_lead_is_visible_only_at_all_scope(): void
    {
        // An inbound STOP from a number nobody has imported has no owner, so
        // there is nothing to scope it by - showing it to everyone would leak
        // it, so it is shown only to those who see everything anyway.
        DncEntry::factory()->create(['lead_id' => null, 'phone_e164' => '+919876500777']);

        $this->actingAsRole(RoleName::Telecaller);
        $this->getJson('/api/v1/dnc')->assertOk()->assertJsonCount(0, 'data.items');

        $this->actingAsRole(RoleName::Admin);
        $this->getJson('/api/v1/dnc')->assertOk()->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function the_blocked_channel_list_is_resolved_from_the_reason(): void
    {
        $this->actingAsRole(RoleName::Admin);
        DncEntry::factory()->wrongNumber()->create();

        // A null channel means "everything this REASON blocks", not
        // "everything" - a wrong phone number leaves email contactable
        // (BR-DNC-02). A client rendering a dash here would be wrong.
        $response = $this->getJson('/api/v1/dnc')
            ->assertOk()
            ->assertJsonPath('data.items.0.channel', null);

        $blocked = $response->json('data.items.0.blocked_channels');

        $this->assertContains('call', $blocked);
        $this->assertNotContains('email', $blocked);
    }

    // -----------------------------------------------------------------------
    // Manual suppression
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lead_can_be_suppressed_manually(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['is_suppressed' => false]);

        $this->postJson('/api/v1/dnc', [
            'lead_id' => $lead->id,
            'reason' => DncReason::DoNotContact->value,
            'note' => 'Asked on the phone.',
        ])->assertCreated();

        $this->assertDatabaseHas('dnc_entries', [
            'lead_id' => $lead->id,
            'reason' => DncReason::DoNotContact->value,
            'source' => 'manual',
            'active' => true,
        ]);

        // The denormalised flag is a cache of the table, kept in step by the
        // service rather than written by the caller.
        $this->assertTrue($lead->fresh()->is_suppressed);
    }

    #[Test]
    public function manual_suppression_does_not_stack_duplicate_rows(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $payload = ['lead_id' => $lead->id, 'reason' => DncReason::OptedOut->value];

        $this->postJson('/api/v1/dnc', $payload)->assertCreated();
        $this->postJson('/api/v1/dnc', $payload)->assertCreated();

        // A suppression list that grows a row per retry is one nobody can
        // audit.
        $this->assertSame(1, DncEntry::where('lead_id', $lead->id)->count());
    }

    #[Test]
    public function a_channel_specific_opt_out_leaves_other_channels_alone(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $this->postJson('/api/v1/dnc', [
            'lead_id' => $lead->id,
            'reason' => DncReason::OptedOut->value,
            'channel' => Channel::Sms->value,
        ])->assertCreated()
            ->assertJsonPath('data.blocked_channels', ['sms']);
    }

    // -----------------------------------------------------------------------
    // Removal (BR-DNC-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_cannot_lift_a_suppression(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);
        $entry = DncEntry::factory()
            ->for(Lead::factory()->create(['assigned_to' => $me->id]))
            ->create();

        // Their own lead, and still refused: the person most motivated to
        // un-suppress a lead is the one whose target it was.
        $this->deleteJson("/api/v1/dnc/{$entry->id}", ['reason' => 'Changed their mind'])
            ->assertStatus(403);

        $this->assertTrue($entry->fresh()->active);
    }

    #[Test]
    public function removing_a_suppression_deactivates_it_without_destroying_it(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);
        $entry = DncEntry::factory()->create();

        $this->deleteJson("/api/v1/dnc/{$entry->id}", [
            'reason' => 'Customer called and asked to be reinstated',
        ])->assertOk()->assertJsonPath('data.active', false);

        $fresh = $entry->fresh();

        // The row survives. A DELETE would destroy exactly the evidence a
        // compliance question needs (BR-DNC-06).
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->active);
        $this->assertSame($actor->id, $fresh->removed_by);
        $this->assertNotNull($fresh->removed_at);
        $this->assertSame('Customer called and asked to be reinstated', $fresh->removal_reason);
    }

    #[Test]
    public function removal_requires_a_written_reason(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $entry = DncEntry::factory()->create();

        $this->deleteJson("/api/v1/dnc/{$entry->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'reason');

        $this->assertTrue($entry->fresh()->active);
    }

    #[Test]
    public function an_already_removed_suppression_cannot_be_removed_again(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $entry = DncEntry::factory()->inactive()->create();

        // Not silently idempotent: a second removal would overwrite the first
        // one's actor and reason, losing who actually lifted it.
        $this->deleteJson("/api/v1/dnc/{$entry->id}", ['reason' => 'Trying again'])
            ->assertStatus(422);
    }

    #[Test]
    public function lifting_one_suppression_leaves_a_lead_suppressed_by_another(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $bounced = DncEntry::factory()->for($lead)->reason(DncReason::BouncedEmail)->create();
        DncEntry::factory()->for($lead)->reason(DncReason::WrongNumber)->create();

        $this->deleteJson("/api/v1/dnc/{$bounced->id}", ['reason' => 'Email address corrected'])
            ->assertOk();

        // The flag is recomputed from the table, never simply cleared - the
        // wrong-number entry still stands.
        $this->assertTrue($lead->fresh()->is_suppressed);
    }

    #[Test]
    public function lifting_the_last_suppression_clears_the_lead_flag(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['is_suppressed' => true]);
        $entry = DncEntry::factory()->for($lead)->create();

        $this->deleteJson("/api/v1/dnc/{$entry->id}", ['reason' => 'Suppressed in error'])
            ->assertOk();

        $this->assertFalse($lead->fresh()->is_suppressed);
    }

    #[Test]
    public function lifting_a_suppression_is_audited(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);
        $entry = DncEntry::factory()->create();

        $this->deleteJson("/api/v1/dnc/{$entry->id}", ['reason' => 'Reinstated on request'])
            ->assertOk();

        // dnc.remove is in Permission::isAudited(), so the middleware records
        // the use before the controller runs (SEC-AUD-02, FR-DNC-04).
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'permission_used',
            'description' => 'dnc.remove',
        ]);
    }
}
