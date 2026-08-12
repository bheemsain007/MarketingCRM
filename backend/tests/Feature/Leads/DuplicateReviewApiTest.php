<?php

namespace Tests\Feature\Leads;

use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\LeadDuplicateCandidate;
use App\Models\Role;
use App\Models\User;
use App\Services\Dnc\DncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The duplicate review queue and its screen (T-64, BR-DUP-03/04).
 *
 * Merging is gated on `leads.archive` rather than `leads.update`: it is
 * irreversible and takes a record out of circulation, which is archiving's
 * authority rather than editing's.
 */
class DuplicateReviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());
        $this->actingAs($user = $user->fresh(), 'sanctum');

        return $user;
    }

    private function pair(): LeadDuplicateCandidate
    {
        $first = Lead::factory()->create(['email' => 'info@acme.example', 'phone_e164' => '+919876500111']);
        $second = Lead::factory()->create(['email' => 'info@acme.example', 'phone_e164' => '+919876500222']);

        return LeadDuplicateCandidate::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'lead_id' => $first->id,
            'duplicate_lead_id' => $second->id,
            'match_type' => 'email',
            'match_value' => 'info@acme.example',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function the_queue_shows_both_sides_of_the_pair(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $candidate = $this->pair();

        $response = $this->getJson('/api/v1/lead-duplicates')->assertOk();

        // The decision is "are these the same person?", which cannot be made
        // from two ids - so both records are summarised inline.
        $this->assertSame($candidate->lead_id, $response->json('data.items.0.lead.id'));
        $this->assertSame($candidate->duplicate_lead_id, $response->json('data.items.0.duplicate.id'));
        $this->assertNotNull($response->json('data.items.0.lead.phone_e164'));
    }

    #[Test]
    public function the_queue_defaults_to_what_still_needs_a_decision(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $this->pair()->update(['status' => 'dismissed']);

        // A review queue that opens showing everything ever dismissed is one
        // nobody scrolls to the bottom of.
        $this->getJson('/api/v1/lead-duplicates')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/lead-duplicates?filter[status]=dismissed')
            ->assertOk()->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function a_manager_can_merge_and_choose_which_lead_survives(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $candidate = $this->pair();

        $this->postJson("/api/v1/lead-duplicates/{$candidate->id}/merge", [
            'survivor_id' => $candidate->duplicate_lead_id,
            'note' => 'Same person, work phone',
        ])->assertOk();

        // The caller names the survivor: the older record usually has more
        // history, but the newer may be the corrected spelling, and only a
        // person looking at both can say.
        $this->assertNull(Lead::find($candidate->lead_id));
        $this->assertNotNull(Lead::find($candidate->duplicate_lead_id));
        $this->assertSame('merged', $candidate->refresh()->status);
    }

    #[Test]
    public function a_survivor_from_outside_the_pair_is_refused(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $candidate = $this->pair();
        $unrelated = Lead::factory()->create();

        // Otherwise the endpoint is a merge-any-two-leads primitive reachable
        // by editing one field.
        $this->postJson("/api/v1/lead-duplicates/{$candidate->id}/merge", [
            'survivor_id' => $unrelated->id,
        ])->assertStatus(422);

        $this->assertNotNull(Lead::find($candidate->lead_id));
    }

    #[Test]
    public function a_telecaller_cannot_merge_or_dismiss(): void
    {
        $candidate = $this->pair();
        $this->actingAsRole(RoleName::Telecaller);

        // Irreversible and takes a record out of circulation - the same
        // authority archiving needs, which a telecaller does not hold.
        $this->postJson("/api/v1/lead-duplicates/{$candidate->id}/merge", [
            'survivor_id' => $candidate->lead_id,
        ])->assertStatus(403);

        $this->postJson("/api/v1/lead-duplicates/{$candidate->id}/dismiss", ['note' => 'nope'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_dismissal_requires_a_reason(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $candidate = $this->pair();

        // The dismissal is what stops the pair being raised again, so the next
        // reviewer deserves to know why.
        $this->postJson("/api/v1/lead-duplicates/{$candidate->id}/dismiss", [])
            ->assertStatus(422);

        $this->postJson("/api/v1/lead-duplicates/{$candidate->id}/dismiss", [
            'note' => 'Colleagues sharing info@',
        ])->assertOk();

        $this->assertSame('dismissed', $candidate->refresh()->status);
    }

    #[Test]
    public function merging_through_the_api_still_unions_suppression(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $candidate = $this->pair();

        app(DncService::class)->suppress(
            Lead::find($candidate->lead_id), DncReason::OptedOut, channel: null, source: 'manual',
        );

        $this->postJson("/api/v1/lead-duplicates/{$candidate->id}/merge", [
            'survivor_id' => $candidate->duplicate_lead_id,
        ])->assertOk();

        // The rule holds through the HTTP path too, not only in the service.
        $this->assertTrue(Lead::find($candidate->duplicate_lead_id)->is_suppressed);
    }

    #[Test]
    public function a_merge_is_written_to_the_audit_log(): void
    {
        $actor = $this->actingAsRole(RoleName::Manager);
        $candidate = $this->pair();

        $this->postJson("/api/v1/lead-duplicates/{$candidate->id}/merge", [
            'survivor_id' => $candidate->lead_id,
        ])->assertOk();

        // A merge is the one lead operation with no undo (SEC-AUD-04).
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'lead_merged',
        ]);
    }

    #[Test]
    public function the_review_screen_loads_and_a_telecaller_gets_no_link(): void
    {
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::where('name', RoleName::Manager->value)->first());

        $this->actingAs($manager->fresh())->get('/lead-duplicates')->assertOk();

        $telecaller = User::factory()->create();
        $telecaller->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());

        // The page is readable with leads.view, but the nav link is drawn for
        // leads.archive - offering a queue nobody can action is noise.
        $this->actingAs($telecaller->fresh())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('href="/lead-duplicates"', false);
    }
}
