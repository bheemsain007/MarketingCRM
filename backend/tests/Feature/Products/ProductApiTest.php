<?php

namespace Tests\Feature\Products;

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Products API (Phase 5, PROJECT_REQUIREMENTS §1.1).
 */
class ProductApiTest extends TestCase
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
    public function the_seven_business_products_are_seeded(): void
    {
        $this->seed(ProductSeeder::class);
        $this->actingAsRole(RoleName::Telecaller);

        $response = $this->getJson('/api/v1/products')->assertOk();

        $this->assertSame(7, $response->json('data.meta.total'));

        $codes = array_column($response->json('data.items'), 'code');
        foreach (['NEWS_PORTAL', 'NGO_PORTAL', 'EPAPER', 'BUSINESS_WEBSITE',
            'SHOPPING_PORTAL', 'MATRIMONIAL', 'NEWS_POSTING'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    #[Test]
    public function products_are_listed_in_business_order_by_default(): void
    {
        $this->seed(ProductSeeder::class);
        $this->actingAsRole(RoleName::Viewer);

        $items = $this->getJson('/api/v1/products')->assertOk()->json('data.items');

        $this->assertSame('NEWS_PORTAL', $items[0]['code']);
    }

    #[Test]
    public function the_list_uses_the_standard_envelope_and_pagination(): void
    {
        Product::factory()->count(12)->create();
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/products?per_page=5')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['items', 'meta'], 'errors'])
            ->assertJsonPath('data.meta.total', 12)
            ->assertJsonCount(5, 'data.items');
    }

    #[Test]
    public function it_filters_and_sorts_on_allowed_fields(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Product::factory()->create(['delivery_type' => 'saas', 'base_price' => 5000]);
        Product::factory()->create(['delivery_type' => 'saas', 'base_price' => 9000]);
        Product::factory()->create(['delivery_type' => 'project', 'base_price' => 1000]);

        $this->getJson('/api/v1/products?filter[delivery_type]=saas')
            ->assertOk()->assertJsonPath('data.meta.total', 2);

        $items = $this->getJson('/api/v1/products?sort=-base_price')->assertOk()->json('data.items');
        $this->assertSame('9000.00', $items[0]['base_price']);
    }

    #[Test]
    public function an_unknown_filter_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/products?filter[secret]=1')->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Permissions
    // -----------------------------------------------------------------------

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/products')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'auth.unauthenticated');
    }

    #[Test]
    public function a_telecaller_can_read_products_but_not_change_them(): void
    {
        // Product names appear throughout the CRM, so read is broad; writing is
        // an administrative act.
        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson('/api/v1/products')->assertOk();

        $this->postJson('/api/v1/products', [
            'code' => 'HACK', 'name' => 'Nope', 'delivery_type' => 'saas',
        ])->assertStatus(403)->assertJsonPath('errors.0.code', 'auth.forbidden');
    }

    #[Test]
    public function a_viewer_cannot_create_update_or_archive(): void
    {
        $product = Product::factory()->create();
        $this->actingAsRole(RoleName::Viewer);

        $this->postJson('/api/v1/products', ['code' => 'X', 'name' => 'X', 'delivery_type' => 'saas'])
            ->assertStatus(403);
        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Changed'])->assertStatus(403);
        $this->deleteJson("/api/v1/products/{$product->id}")->assertStatus(403);
    }

    #[Test]
    public function an_admin_can_manage_products(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/products', [
            'code' => 'NEW_PRODUCT',
            'name' => 'New Product',
            'delivery_type' => 'saas',
            'base_price' => 25000,
        ])->assertStatus(201)->assertJsonPath('data.code', 'NEW_PRODUCT');

        $this->assertDatabaseHas('products', ['code' => 'NEW_PRODUCT', 'tenant_id' => 0]);
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    #[Test]
    public function creating_a_product_validates_required_fields(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $response = $this->postJson('/api/v1/products', [])->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        $this->assertContains('code', $fields);
        $this->assertContains('name', $fields);
        $this->assertContains('delivery_type', $fields);
    }

    #[Test]
    public function a_product_code_is_normalised_to_uppercase(): void
    {
        // Keeps codes consistent so integrations and reports can rely on them.
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/products', [
            'code' => '  my_code  ', 'name' => 'Test', 'delivery_type' => 'service',
        ])->assertStatus(201)->assertJsonPath('data.code', 'MY_CODE');
    }

    #[Test]
    public function an_invalid_delivery_type_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/products', [
            'code' => 'ABC', 'name' => 'Test', 'delivery_type' => 'nonsense',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_duplicate_code_returns_a_clean_conflict_not_a_database_error(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $existing = Product::factory()->create(['code' => 'TAKEN']);

        $this->postJson('/api/v1/products', [
            'code' => 'TAKEN', 'name' => 'Duplicate', 'delivery_type' => 'saas',
        ])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'resource.conflict')
            ->assertJsonPath('data.existing_product_id', $existing->id);
    }

    #[Test]
    public function a_code_held_by_an_archived_product_is_reported_as_such(): void
    {
        // Otherwise the caller sees "already exists" for a product they cannot
        // find anywhere in the UI.
        $this->actingAsRole(RoleName::Admin);
        $archived = Product::factory()->create(['code' => 'OLD_ONE']);
        $archived->delete();

        $this->postJson('/api/v1/products', [
            'code' => 'OLD_ONE', 'name' => 'Reused', 'delivery_type' => 'saas',
        ])
            ->assertStatus(409)
            ->assertJsonPath('data.archived', true);
    }

    // -----------------------------------------------------------------------
    // Archiving
    // -----------------------------------------------------------------------

    #[Test]
    public function archiving_soft_deletes_and_deactivates(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $product = Product::factory()->create(['is_active' => true]);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        // Deactivated too, so anything filtering on is_active also excludes it.
        $this->assertFalse((bool) Product::withTrashed()->find($product->id)->is_active);
    }

    #[Test]
    public function a_product_with_lead_interest_survives_archiving(): void
    {
        // The rule that matters: product history feeds performance reporting.
        // Archiving must never take interest records with it.
        $this->actingAsRole(RoleName::Admin);

        $product = Product::factory()->create();
        $lead = Lead::factory()->create();
        $lead->leadProducts()->create(['product_id' => $product->id]);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertDatabaseHas('lead_products', [
            'lead_id' => $lead->id,
            'product_id' => $product->id,
        ]);
    }

    #[Test]
    public function archived_products_are_hidden_unless_requested(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Product::factory()->count(2)->create();
        Product::factory()->create()->delete();

        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/products?with_archived=1')->assertOk()->assertJsonPath('data.meta.total', 3);
    }

    #[Test]
    public function an_archived_product_can_be_restored(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $product = Product::factory()->create();
        $product->delete();

        $this->postJson("/api/v1/products/{$product->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.is_archived', false)
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function requesting_a_missing_product_returns_the_envelope(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/products/999999')
            ->assertStatus(404)
            ->assertJsonPath('errors.0.code', 'resource.not_found');
    }

    #[Test]
    public function updating_a_product_records_the_editor(): void
    {
        $user = $this->actingAsRole(RoleName::Admin);
        $product = Product::factory()->create(['name' => 'Before']);

        $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'After'])
            ->assertOk()
            ->assertJsonPath('data.name', 'After');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'updated_by' => $user->id]);
    }
}
