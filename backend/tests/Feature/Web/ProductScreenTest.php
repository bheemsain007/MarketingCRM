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
 * Product catalogue controls (PROJECT_REQUIREMENTS §1.1).
 *
 * The page is a shell - rows arrive from /api/v1/products - so what is worth
 * asserting here is which CONTROLS it draws. `products.view` is held by every
 * role because product names appear all over the CRM, while `products.manage`
 * is Admin and above; a Manager therefore gets a complete, working page with no
 * write controls on it, and that is the case most likely to regress.
 */
class ProductScreenTest extends TestCase
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
    public function an_admin_sees_the_create_edit_and_archive_controls(): void
    {
        $this->actingAs($this->user(RoleName::Admin))
            ->get('/products')
            ->assertOk()
            ->assertSee('New product')
            ->assertSee('Save product')
            ->assertSee('Archive product');
    }

    #[Test]
    public function a_user_without_products_view_cannot_open_the_page(): void
    {
        // Every seeded role holds products.view, so the case that exercises the
        // route gate is an account carrying no role at all.
        $this->actingAs(User::factory()->create())
            ->get('/products')
            ->assertStatus(403);
    }

    #[Test]
    public function the_nav_link_appears_for_a_role_that_can_read_products(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            // Path only, not the full URL: the host comes from APP_URL.
            ->assertSee('/products"', false);
    }

    #[Test]
    public function a_manager_reads_the_catalogue_without_any_write_controls(): void
    {
        // A Manager holds products.view but not products.manage. The page must
        // still be fully readable - hiding the whole screen would be worse -
        // with no control on it that could only ever return a 403.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/products')
            ->assertOk()
            ->assertSee('Show archived')
            ->assertDontSee('id="new-product"', false)
            ->assertDontSee('Save product')
            ->assertDontSee('Archive product');
    }

    #[Test]
    public function a_telecaller_gets_no_write_controls_either(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/products')
            ->assertOk()
            ->assertDontSee('Save product');
    }

    #[Test]
    public function the_page_states_that_products_are_archived_rather_than_deleted(): void
    {
        // The rule the screen exists to make visible: lead interest and product
        // reporting have to survive a product leaving the catalogue.
        $this->actingAs($this->user(RoleName::Admin))
            ->get('/products')
            ->assertOk()
            ->assertSee('archived rather than deleted');
    }
}
