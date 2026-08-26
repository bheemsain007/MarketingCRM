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
 * The template management screen (FR-COMM-02, T-31).
 *
 * The page is a shell - rows arrive from `/api/v1/templates` - so what is worth
 * asserting here is the split between the two permissions. `templates.view` is
 * held by every telecaller because they pick a template every time they send;
 * `templates.manage` is the authority to change what the organisation says in
 * its own name. Authoring is not sending, and the screen must show that.
 *
 * Nothing here depends on the clock, so no time is frozen.
 */
class TemplateScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** @param array<string, mixed> $attributes */
    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    #[Test]
    public function a_manager_can_open_the_template_screen_with_the_authoring_controls(): void
    {
        // Manager holds both templates.view and templates.manage.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/templates')
            ->assertOk()
            ->assertSee('Preview against a lead')
            ->assertSee('New template')
            ->assertSee('Save template')
            ->assertSee('Retire this template')
            ->assertSee('const canManage = true', false);
    }

    #[Test]
    public function a_telecaller_may_read_templates_but_is_offered_no_way_to_author_one(): void
    {
        /*
         * Telecallers hold templates.view and not templates.manage. The routes
         * refuse a write either way (SEC-AUTHZ-02); this asserts the screen does
         * not draw a button that would 403, and that the preview - which is a
         * read - is still there.
         */
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/templates')
            ->assertOk()
            ->assertSee('Preview against a lead')
            ->assertSee('const canManage = false', false)
            ->assertDontSee('New template')
            ->assertDontSee('Save template')
            ->assertDontSee('Retire this template')
            ->assertDontSee('id="t-save"', false);
    }

    #[Test]
    public function a_role_without_templates_view_cannot_open_the_screen(): void
    {
        // Accounts works on payments and holds no template permission at all.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/templates')
            ->assertStatus(403);
    }

    #[Test]
    public function navigation_shows_templates_only_to_those_who_may_see_them(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/templates"', false);

        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/templates"', false);
    }

    #[Test]
    public function the_channel_pickers_offer_no_voice_channel(): void
    {
        /*
         * A template on `call` or `ai_call` is refused by the API - those place
         * a call rather than sending anything (ChecksTemplateChannel). Offering
         * them would be an option whose only outcome is a 422.
         */
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/templates')
            ->assertOk()
            ->assertSee('<option value="whatsapp">', false)
            ->assertDontSee('<option value="call">', false)
            ->assertDontSee('<option value="ai_call">', false);
    }

    #[Test]
    public function the_form_offers_no_approval_control(): void
    {
        // Approval is derived from the channel and the API refuses a submitted
        // one outright (T-31), so there is no field for it to come from.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/templates')
            ->assertOk()
            ->assertDontSee('id="t-approval_status"', false)
            ->assertDontSee('id="t-rejection_reason"', false);
    }
}
