<?php

namespace Tests\Feature\Api;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * List conventions: filtering, sorting, includes, pagination
 * (API_DOCUMENTATION §4, FR-LEAD-02).
 */
class QueryOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('api/v1/t-leads', function (Request $request) {
            $options = new QueryOptions(
                $request,
                allowedFilters: ['status', 'temperature', 'priority', 'created_at'],
                allowedSorts: ['created_at', 'priority', 'name'],
                allowedIncludes: ['products'],
            );

            return ApiResponse::paginated(
                $options->applyTo(Lead::query())->paginate($options->perPage())
            );
        });
    }

    #[Test]
    public function it_paginates_with_metadata_under_data(): void
    {
        Lead::factory()->count(30)->create();

        $this->getJson('/api/v1/t-leads?per_page=10')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]])
            ->assertJsonPath('data.meta.total', 30)
            ->assertJsonPath('data.meta.per_page', 10)
            ->assertJsonPath('data.meta.last_page', 3)
            ->assertJsonCount(10, 'data.items');
    }

    #[Test]
    public function per_page_is_capped_rather_than_rejected(): void
    {
        Lead::factory()->count(3)->create();

        $this->getJson('/api/v1/t-leads?per_page=5000')
            ->assertOk()
            ->assertJsonPath('data.meta.per_page', config('crm.api.max_per_page'));
    }

    #[Test]
    public function it_filters_on_an_allowed_field(): void
    {
        Lead::factory()->count(2)->create(['status' => LeadStatus::Interested->value]);
        Lead::factory()->count(5)->create(['status' => LeadStatus::New->value]);

        $this->getJson('/api/v1/t-leads?filter[status]=interested')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    #[Test]
    public function it_supports_the_in_operator(): void
    {
        Lead::factory()->create(['status' => LeadStatus::New->value]);
        Lead::factory()->create(['status' => LeadStatus::Contacted->value]);
        Lead::factory()->create(['status' => LeadStatus::Lost->value]);

        $this->getJson('/api/v1/t-leads?filter[status][in]=new,contacted')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    #[Test]
    public function an_unknown_filter_field_is_rejected_not_ignored(): void
    {
        // The important one. A silently dropped filter returns MORE rows than
        // the caller asked for - on a scoped lead list that is data exposure,
        // not a cosmetic bug.
        Lead::factory()->count(3)->create();

        $this->getJson('/api/v1/t-leads?filter[secret_column]=x')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.code', 'validation.failed');
    }

    #[Test]
    public function an_unknown_sort_field_is_rejected(): void
    {
        $this->getJson('/api/v1/t-leads?sort=password')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'validation.failed');
    }

    #[Test]
    public function an_unknown_include_is_rejected(): void
    {
        $this->getJson('/api/v1/t-leads?include=notAllowed')
            ->assertStatus(422);
    }

    #[Test]
    public function a_minus_prefix_sorts_descending(): void
    {
        Lead::factory()->create(['name' => 'Aaron', 'priority' => 1]);
        Lead::factory()->create(['name' => 'Zara', 'priority' => 9]);

        $desc = $this->getJson('/api/v1/t-leads?sort=-priority')->assertOk();
        $this->assertSame(9, $desc->json('data.items.0.priority'));

        $asc = $this->getJson('/api/v1/t-leads?sort=priority')->assertOk();
        $this->assertSame(1, $asc->json('data.items.0.priority'));
    }

    #[Test]
    public function filters_combine_with_and_semantics(): void
    {
        Lead::factory()->create(['status' => LeadStatus::Interested->value, 'priority' => 5]);
        Lead::factory()->create(['status' => LeadStatus::Interested->value, 'priority' => 1]);
        Lead::factory()->create(['status' => LeadStatus::New->value, 'priority' => 5]);

        $this->getJson('/api/v1/t-leads?filter[status]=interested&filter[priority]=5')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function an_unsupported_operator_is_rejected(): void
    {
        $this->getJson('/api/v1/t-leads?filter[priority][regex]=.*')
            ->assertStatus(422);
    }
}
