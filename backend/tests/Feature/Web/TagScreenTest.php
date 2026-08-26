<?php

namespace Tests\Feature\Web;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tag vocabulary screen (FR-LEAD-03, BR-INT-02).
 *
 * Gated on `settings.manage`, the same gate the write half of /api/v1/tags
 * carries - tags are organisation reference data, so the people who may change
 * them are the people who may change settings. Reading the vocabulary is wider
 * (leads.view) because every telecaller labels leads, but that read happens on
 * the lead form, not here.
 */
class TagScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    #[Test]
    public function an_admin_sees_the_tag_management_controls(): void
    {
        $this->actingAs($this->user(RoleName::Admin))
            ->get('/tags')
            ->assertOk()
            ->assertSee('New tag')
            ->assertSee('Save tag')
            ->assertSee('Delete tag');
    }

    #[Test]
    public function the_screen_states_that_automatic_tags_cannot_be_changed(): void
    {
        // The rule lives in TagPolicy; the page has to SAY it, or an admin who
        // finds a system tag with no buttons on it reads that as a broken page.
        $this->actingAs($this->user(RoleName::Admin))
            ->get('/tags')
            ->assertOk()
            ->assertSee('not renamed or deleted');
    }

    #[Test]
    public function a_role_without_settings_manage_cannot_open_the_page(): void
    {
        // A Manager holds most operational permissions and none of these.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/tags')
            ->assertStatus(403);
    }

    #[Test]
    public function a_telecaller_cannot_open_the_page_either(): void
    {
        // A telecaller reads the vocabulary constantly - on the lead form - but
        // changing what the organisation can label a lead with is not theirs.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/tags')
            ->assertStatus(403);
    }

    #[Test]
    public function the_nav_link_appears_for_an_admin(): void
    {
        $this->actingAs($this->user(RoleName::Admin))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/tags"', false);
    }

    #[Test]
    public function the_nav_link_is_hidden_from_a_role_that_cannot_use_it(): void
    {
        // Usability only - the route middleware is what actually refuses the
        // URL, as the 403 tests above assert.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/tags"', false);
    }
}
