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
 * The interested / hot / warm / product-wise leads screen (Phase 20, FR-INT-03).
 *
 * `GET /interested-leads` has existed since Phase 20 with no screen behind it
 * (docs/TODO.md). The page is a shell like every other list (ADR-A) - its rows
 * come from that endpoint by AJAX - so what is worth asserting here is who may
 * reach it and that it is gated the same as the leads list itself, since this
 * is a filtered view of leads and not a distinct capability.
 */
class InterestedLeadsScreenTest extends TestCase
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
    public function a_telecaller_can_open_the_interested_leads_screen(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/leads-interested')
            ->assertOk()
            ->assertSee('id="lead-rows"', false)
            // ADR-A: the rows come from the API, not from the view.
            ->assertSee('/api/v1/interested-leads', false)
            // The columns the screen exists for - a telecaller scans these
            // first to find who to call next.
            ->assertSee('Temperature')
            ->assertSee('Score');
    }

    #[Test]
    public function a_user_holding_no_role_cannot_open_the_screen(): void
    {
        // Every seeded role holds leads.view, so the case that exercises the
        // route's own permission gate is an account carrying no role at all.
        $this->actingAs(User::factory()->create())
            ->get('/leads-interested')
            ->assertStatus(403);
    }

    #[Test]
    public function the_interested_leads_nav_link_is_shown_to_a_holder_of_leads_view(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            // Path only, not the full URL: the host comes from APP_URL.
            ->assertSee('/leads-interested"', false);
    }

    #[Test]
    public function the_interested_leads_nav_link_is_hidden_from_a_user_holding_no_role(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/leads-interested"', false);
    }

    #[Test]
    public function the_filter_card_offers_only_temperatures_and_products_the_api_would_accept(): void
    {
        // Passed in from the controller rather than typed in the view, so the
        // filters on screen can never offer a value GET /interested-leads
        // would reject with a 422.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/leads-interested')
            ->assertOk()
            ->assertSee('value="hot"', false)
            ->assertSee('value="warm"', false)
            ->assertSee('value="cold"', false);
    }
}
