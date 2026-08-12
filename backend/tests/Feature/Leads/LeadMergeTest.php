<?php

namespace Tests\Feature\Leads;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Models\Call;
use App\Models\Lead;
use App\Models\LeadDuplicateCandidate;
use App\Models\Message;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Dnc\DncService;
use App\Services\Leads\DuplicateDetector;
use App\Services\Leads\LeadMergeService;
use App\Services\Leads\LeadService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Duplicate review and merge (BR-DUP-03, BR-DUP-04, T-64).
 *
 * The assertion that matters most is that **a merge can never un-suppress
 * anybody**. Every other mistake here can be corrected by hand afterwards;
 * that one puts a person who asked not to be contacted back on a dialling list.
 */
class LeadMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Admin->value)->first());

        return $user->fresh();
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::factory()->create($attributes);
    }

    private function merge(Lead $survivor, Lead $duplicate): Lead
    {
        return app(LeadMergeService::class)->merge($survivor, $duplicate, $this->admin());
    }

    // -----------------------------------------------------------------------
    // Suppression is union (BR-DUP-04) - the rule that must not bend
    // -----------------------------------------------------------------------

    #[Test]
    public function merging_a_suppressed_lead_suppresses_the_survivor(): void
    {
        $survivor = $this->lead();
        $duplicate = $this->lead();

        app(DncService::class)->suppress($duplicate, DncReason::OptedOut, channel: null, source: 'manual');

        $this->assertTrue(app(DncService::class)->canContact($survivor, Channel::Email));

        $this->merge($survivor, $duplicate);

        // Union, not replacement. Merging must never be a way to reach somebody
        // who opted out under their other record.
        $this->assertFalse(app(DncService::class)->canContact($survivor->refresh(), Channel::Email));
        $this->assertTrue($survivor->refresh()->is_suppressed);
    }

    #[Test]
    public function merging_an_unsuppressed_lead_never_lifts_existing_suppression(): void
    {
        $survivor = $this->lead();
        $duplicate = $this->lead();

        app(DncService::class)->suppress($survivor, DncReason::DoNotContact, channel: null, source: 'manual');

        $this->merge($survivor, $duplicate);

        $this->assertTrue($survivor->refresh()->is_suppressed);
        $this->assertFalse(app(DncService::class)->canContact($survivor->refresh(), Channel::Call));
    }

    // -----------------------------------------------------------------------
    // History survives (BR-DUP-04)
    // -----------------------------------------------------------------------

    #[Test]
    public function calls_messages_and_notes_move_to_the_survivor(): void
    {
        $survivor = $this->lead();
        $duplicate = $this->lead();

        Call::factory()->count(2)->create(['lead_id' => $duplicate->id]);
        Message::factory()->count(3)->create(['lead_id' => $duplicate->id]);

        $this->merge($survivor, $duplicate);

        $this->assertSame(2, DB::table('calls')->where('lead_id', $survivor->id)->count());
        $this->assertSame(3, DB::table('messages')->where('lead_id', $survivor->id)->count());
        $this->assertSame(0, DB::table('calls')->where('lead_id', $duplicate->id)->count());
    }

    #[Test]
    public function the_merged_lead_is_kept_and_points_at_its_survivor(): void
    {
        $survivor = $this->lead();
        $duplicate = $this->lead();

        $this->merge($survivor, $duplicate);

        // Soft-deleted, not destroyed: retaining history is the whole point of
        // BR-DUP-04, and a merge has no undo.
        $merged = Lead::withTrashed()->findOrFail($duplicate->id);
        $this->assertTrue($merged->trashed());
        $this->assertSame($survivor->id, $merged->merged_into_id);
        $this->assertNotNull($merged->merged_at);
    }

    #[Test]
    public function the_merge_is_recorded_on_the_survivors_timeline(): void
    {
        $survivor = $this->lead();
        $duplicate = $this->lead(['name' => 'Ramesh Kumar']);

        $this->merge($survivor, $duplicate);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $survivor->id,
            'activity_type' => 'lead_merged',
        ]);

        $activity = DB::table('lead_activities')
            ->where('lead_id', $survivor->id)
            ->where('activity_type', 'lead_merged')
            ->first();

        $this->assertStringContainsString('Ramesh Kumar', (string) $activity->description);
    }

    #[Test]
    public function a_shared_product_does_not_break_the_merge(): void
    {
        $survivor = $this->lead();
        $duplicate = $this->lead();
        $product = Product::factory()->create();

        // Both leads interested in the same product: repointing blindly would
        // violate unique(lead_id, product_id).
        foreach ([$survivor, $duplicate] as $lead) {
            DB::table('lead_products')->insert([
                'lead_id' => $lead->id, 'product_id' => $product->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->merge($survivor, $duplicate);

        $this->assertSame(
            1,
            DB::table('lead_products')->where('lead_id', $survivor->id)->count(),
        );
    }

    // -----------------------------------------------------------------------
    // Refusals
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lead_cannot_be_merged_into_itself(): void
    {
        $lead = $this->lead();

        $this->expectException(ApiException::class);
        $this->merge($lead, $lead);
    }

    #[Test]
    public function a_lead_cannot_be_merged_twice(): void
    {
        $survivor = $this->lead();
        $duplicate = $this->lead();

        $this->merge($survivor, $duplicate);

        $this->expectException(ApiException::class);
        $this->merge($this->lead(), $duplicate->refresh());
    }

    #[Test]
    public function the_merge_chain_resolves_to_the_lead_holding_the_history(): void
    {
        $final = $this->lead();
        $middle = $this->lead();
        $first = $this->lead();

        $this->merge($middle, $first);
        $this->merge($final, $middle->refresh());

        // A number enquiring again must land on the record that actually has
        // the history, not on a deleted row two hops back.
        $resolved = app(LeadMergeService::class)->resolve(Lead::withTrashed()->find($first->id));
        $this->assertSame($final->id, $resolved->id);
    }

    // -----------------------------------------------------------------------
    // Email is a secondary signal only (BR-DUP-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_shared_email_flags_a_candidate_and_never_merges(): void
    {
        $first = app(LeadService::class)->create([
            'name' => 'Anita', 'phone' => '9876500111', 'email' => 'info@acme.example',
        ]);

        $second = app(LeadService::class)->create([
            'name' => 'Bharat', 'phone' => '9876500222', 'email' => 'info@acme.example',
        ]);

        /*
         * Both leads still exist. Two people at one company legitimately share
         * `info@`, and auto-merging on that evidence fuses distinct humans -
         * with no undo.
         */
        $this->assertNotNull(Lead::find($first->id));
        $this->assertNotNull(Lead::find($second->id));
        $this->assertNull($second->refresh()->merged_into_id);

        $this->assertDatabaseHas('lead_duplicate_candidates', [
            'lead_id' => $first->id,
            'duplicate_lead_id' => $second->id,
            'match_type' => 'email',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function the_same_pair_is_only_ever_recorded_once(): void
    {
        $first = $this->lead(['email' => 'shared@example.com', 'phone_e164' => '+919876500111']);
        $second = $this->lead(['email' => 'shared@example.com', 'phone_e164' => '+919876500222']);

        app(DuplicateDetector::class)->check($second);
        app(DuplicateDetector::class)->check($first);
        app(DuplicateDetector::class)->check($second);

        // The pair is one question. Recording it from both ends would leave a
        // reviewer answering half of it.
        $this->assertSame(1, LeadDuplicateCandidate::count());
    }

    #[Test]
    public function a_dismissed_pair_is_not_raised_again(): void
    {
        $first = $this->lead(['email' => 'shared@example.com', 'phone_e164' => '+919876500111']);
        $second = $this->lead(['email' => 'shared@example.com', 'phone_e164' => '+919876500222']);

        $candidate = app(DuplicateDetector::class)->check($second);
        $candidate->update(['status' => 'dismissed', 'resolution_note' => 'Different people, same office']);

        app(DuplicateDetector::class)->check($second);

        // "These are different people" is an answer. Re-asking it on every
        // import is how a review queue becomes noise nobody reads.
        $this->assertSame('dismissed', $candidate->refresh()->status);
        $this->assertSame(1, LeadDuplicateCandidate::count());
    }

    #[Test]
    public function a_lead_with_no_email_raises_nothing(): void
    {
        $this->lead(['email' => null, 'phone_e164' => '+919876500111']);
        $second = $this->lead(['email' => null, 'phone_e164' => '+919876500222']);

        $this->assertNull(app(DuplicateDetector::class)->check($second));
        $this->assertSame(0, LeadDuplicateCandidate::count());
    }

    #[Test]
    public function the_backfill_finds_pairs_created_before_detection_existed(): void
    {
        // Written straight to the table, as leads captured before this feature
        // would have been.
        $this->lead(['email' => 'old@example.com', 'phone_e164' => '+919876500111']);
        $this->lead(['email' => 'old@example.com', 'phone_e164' => '+919876500222']);
        $this->lead(['email' => 'old@example.com', 'phone_e164' => '+919876500333']);
        LeadDuplicateCandidate::query()->delete();

        $created = app(DuplicateDetector::class)->backfill();

        // Three leads sharing an address is three decisions, not two - only
        // surfacing consecutive pairs would leave one silently unreviewed.
        $this->assertSame(3, $created);
    }
}
