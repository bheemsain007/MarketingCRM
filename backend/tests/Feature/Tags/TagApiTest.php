<?php

namespace Tests\Feature\Tags;

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tag vocabulary API (FR-LEAD-03, BR-INT-02).
 *
 * The rule this file exists to pin down: a system tag is visible to everyone
 * and editable by nobody. The Interest Engine finds its label by name, so a
 * rename breaks the engine silently, and a delete takes the label off every
 * lead carrying it - neither is something a permission should be able to buy.
 */
class TagApiTest extends TestCase
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
        $this->actingAs($user->fresh(), 'sanctum');

        return $user;
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    #[Test]
    public function the_list_uses_the_standard_envelope_and_pagination(): void
    {
        Tag::factory()->count(12)->create();
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/tags?per_page=5')
            ->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'errors',
                'data' => ['items', 'meta' => ['current_page', 'per_page', 'total', 'last_page']],
            ])
            ->assertJsonPath('data.meta.total', 12)
            ->assertJsonPath('data.meta.last_page', 3)
            ->assertJsonCount(5, 'data.items');
    }

    #[Test]
    public function system_tags_are_listed_alongside_user_tags_and_marked_uneditable(): void
    {
        // Visible but not editable: hiding them would leave an admin unable to
        // explain why a lead carries a label no screen admits exists.
        Tag::factory()->create(['name' => 'Priority']);
        Tag::factory()->system()->create(['name' => 'Interested']);

        $this->actingAsRole(RoleName::Admin);

        $items = $this->getJson('/api/v1/tags')->assertOk()->json('data.items');

        $this->assertCount(2, $items);

        $byName = collect($items)->keyBy('name');
        $this->assertTrue($byName['Priority']['is_editable']);
        $this->assertFalse($byName['Priority']['is_system']);
        $this->assertFalse($byName['Interested']['is_editable']);
        $this->assertTrue($byName['Interested']['is_system']);
    }

    #[Test]
    public function the_list_reports_how_many_leads_carry_each_tag(): void
    {
        // The delete confirmation is built on this number, so it has to be in
        // the list response rather than fetched per row.
        $tag = Tag::factory()->create();
        $tag->leads()->attach(Lead::factory()->count(3)->create()->pluck('id'));
        Tag::factory()->create();

        $this->actingAsRole(RoleName::Admin);

        $items = collect($this->getJson('/api/v1/tags')->assertOk()->json('data.items'))->keyBy('id');

        $this->assertSame(3, $items[$tag->id]['leads_count']);
    }

    #[Test]
    public function the_list_can_be_filtered_to_the_editable_vocabulary(): void
    {
        Tag::factory()->count(2)->create();
        Tag::factory()->system()->create();

        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/tags?filter[is_system]=0')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    #[Test]
    public function an_unknown_filter_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/tags?filter[secret]=1')->assertStatus(422);
    }

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/tags')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'auth.unauthenticated');
    }

    // -----------------------------------------------------------------------
    // Who may write
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_can_read_the_vocabulary_but_not_change_it(): void
    {
        // Reading is gated like lead data - a telecaller labels leads all day.
        // Changing the vocabulary is administrative.
        $tag = Tag::factory()->create();
        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson('/api/v1/tags')->assertOk();

        $this->postJson('/api/v1/tags', ['name' => 'Sneaky'])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'auth.forbidden');

        $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Renamed'])->assertStatus(403);
        $this->deleteJson("/api/v1/tags/{$tag->id}")->assertStatus(403);

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => $tag->name]);
    }

    #[Test]
    public function a_manager_cannot_change_the_vocabulary(): void
    {
        // A Manager holds most operational permissions but not settings.manage:
        // tags are organisation reference data, not a team-level decision.
        $tag = Tag::factory()->create();
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/tags', ['name' => 'Manager Tag'])->assertStatus(403);
        $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Renamed'])->assertStatus(403);
        $this->deleteJson("/api/v1/tags/{$tag->id}")->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // System tags
    // -----------------------------------------------------------------------

    #[Test]
    public function a_system_tag_cannot_be_renamed_even_by_a_super_admin(): void
    {
        // Super Admin holds every permission there is, and still cannot do
        // this: the Interest Engine looks the label up by name.
        $tag = Tag::factory()->system()->create(['name' => 'Interested']);
        $this->actingAsRole(RoleName::SuperAdmin);

        $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Keen'])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'auth.forbidden')
            ->assertJsonPath('data.is_system', true);

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => 'Interested']);
    }

    #[Test]
    public function a_system_tag_cannot_be_deleted_even_by_a_super_admin(): void
    {
        $tag = Tag::factory()->system()->create(['name' => 'Interested']);
        $lead = Lead::factory()->create();
        $tag->leads()->attach($lead->id);

        $this->actingAsRole(RoleName::SuperAdmin);

        $this->deleteJson("/api/v1/tags/{$tag->id}")->assertStatus(403);

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
        $this->assertDatabaseHas('lead_tag', ['lead_id' => $lead->id, 'tag_id' => $tag->id]);
    }

    #[Test]
    public function the_refusal_names_the_reason_rather_than_blaming_the_account(): void
    {
        // A bare "you do not have permission" would send an admin who holds
        // every permission hunting through the role screen for nothing.
        $tag = Tag::factory()->system()->create(['name' => 'Interested']);
        $this->actingAsRole(RoleName::Admin);

        $response = $this->deleteJson("/api/v1/tags/{$tag->id}")->assertStatus(403);

        $this->assertStringContainsString('applied automatically', $response->json('message'));
    }

    #[Test]
    public function a_tag_cannot_be_created_as_a_system_tag(): void
    {
        // Otherwise settings.manage buys the ability to mint a tag that not
        // even a Super Admin could afterwards remove.
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/tags', ['name' => 'Undeletable', 'is_system' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.is_system', false);

        $this->assertDatabaseHas('tags', ['name' => 'Undeletable', 'is_system' => false]);
    }

    // -----------------------------------------------------------------------
    // Creating, renaming, deleting
    // -----------------------------------------------------------------------

    #[Test]
    public function an_admin_can_create_a_tag_and_the_slug_is_derived_from_the_name(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/tags', ['name' => '  Hot Lead  ', 'color' => '#FF0000'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Hot Lead')
            ->assertJsonPath('data.slug', 'hot-lead')
            ->assertJsonPath('data.color', '#FF0000')
            ->assertJsonPath('data.leads_count', 0);

        $this->assertDatabaseHas('tags', ['slug' => 'hot-lead', 'tenant_id' => 0]);
    }

    #[Test]
    public function a_tag_created_without_a_colour_takes_the_column_default(): void
    {
        // The column is NOT NULL with a default, so an omitted colour must be
        // omitted from the insert rather than written as null.
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/tags', ['name' => 'Plain'])
            ->assertStatus(201)
            ->assertJsonPath('data.color', '#6B7280');
    }

    #[Test]
    public function creating_a_tag_validates_the_name(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $missing = $this->postJson('/api/v1/tags', [])->assertStatus(422);
        $this->assertContains('name', array_column($missing->json('errors'), 'field'));

        // A name of pure punctuation slugs to an empty string, which would
        // collide with the next one for a reason nobody could see.
        $this->postJson('/api/v1/tags', ['name' => '---'])->assertStatus(422);

        $this->postJson('/api/v1/tags', ['name' => 'Fine', 'color' => 'red'])->assertStatus(422);
    }

    #[Test]
    public function a_duplicate_name_returns_a_clean_conflict_not_a_database_error(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $existing = Tag::factory()->create(['name' => 'VIP', 'slug' => 'vip']);

        // Different capitalisation, same slug - which is what the unique index
        // is on, and what would otherwise be a raw constraint violation.
        $this->postJson('/api/v1/tags', ['name' => 'vip'])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'resource.conflict')
            ->assertJsonPath('data.existing_tag_id', $existing->id);
    }

    #[Test]
    public function an_admin_can_rename_a_user_tag_and_the_slug_follows(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $tag = Tag::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.slug', 'new-name');

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'slug' => 'new-name']);
    }

    #[Test]
    public function renaming_a_tag_onto_an_existing_one_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Tag::factory()->create(['name' => 'Taken', 'slug' => 'taken']);
        $tag = Tag::factory()->create(['name' => 'Mine', 'slug' => 'mine']);

        $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Taken'])->assertStatus(409);

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'slug' => 'mine']);
    }

    #[Test]
    public function recolouring_a_tag_without_renaming_it_keeps_the_slug(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $tag = Tag::factory()->create(['name' => 'Steady', 'slug' => 'steady']);

        $this->patchJson("/api/v1/tags/{$tag->id}", ['color' => '#123ABC'])
            ->assertOk()
            ->assertJsonPath('data.color', '#123ABC')
            ->assertJsonPath('data.slug', 'steady');
    }

    #[Test]
    public function deleting_a_tag_reports_how_many_leads_lost_the_label(): void
    {
        // lead_tag cascades, so the label really is gone. The count is the only
        // record of what the deletion cost, which is why it is in the message.
        $this->actingAsRole(RoleName::Admin);

        $tag = Tag::factory()->create();
        $leads = Lead::factory()->count(2)->create();
        $tag->leads()->attach($leads->pluck('id'));

        $response = $this->deleteJson("/api/v1/tags/{$tag->id}")->assertOk();

        $this->assertStringContainsString('2 leads', $response->json('message'));
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('lead_tag', ['tag_id' => $tag->id]);

        // The leads themselves survive - only the label went.
        $this->assertDatabaseHas('leads', ['id' => $leads->first()->id]);
    }

    #[Test]
    public function deleting_a_missing_tag_returns_the_envelope(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->deleteJson('/api/v1/tags/999999')
            ->assertStatus(404)
            ->assertJsonPath('errors.0.code', 'resource.not_found');
    }
}
