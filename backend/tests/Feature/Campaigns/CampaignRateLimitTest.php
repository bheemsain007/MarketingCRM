<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\RoleName;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rate limiting on the campaign endpoints (NFR-07, API_DOCUMENTATION §7).
 *
 * The campaigns group was the one group in the API carrying no limiter at all -
 * including `start`, which is the most expensive request in the system: one
 * call fans out into a job per targeted lead. An unthrottled start is a
 * self-inflicted denial of service that also spends real money at the provider.
 */
class CampaignRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Admin->value)->first());
        $this->actingAs($user = $user->fresh(), 'sanctum');

        return $user;
    }

    private function campaign(): Campaign
    {
        return Campaign::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'name' => 'August offer',
            'channel' => Channel::Email->value,
            'status' => CampaignStatus::Draft->value,
            'audience_filters' => [],
        ]);
    }

    #[Test]
    public function starting_a_campaign_is_held_to_the_bulk_limit(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        $campaign = $this->campaign();

        $limit = (int) config('crm.api.rate_limits.bulk');

        // Every response inside the allowance counts, whatever its status: the
        // limiter runs before the controller, and a refused transition still
        // cost a request.
        for ($i = 0; $i < $limit; $i++) {
            $this->assertNotSame(
                429,
                $this->postJson("/api/v1/campaigns/{$campaign->id}/start")->status(),
            );
        }

        $this->postJson("/api/v1/campaigns/{$campaign->id}/start")
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.code', 'rate_limit.exceeded');
    }

    #[Test]
    public function previewing_an_audience_is_held_to_the_bulk_limit_too(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->campaign();

        // Preview walks the whole audience and asks the eligibility gate about
        // every lead in it. It sends nothing, but it costs what a start costs
        // minus the provider calls - so it belongs on the bulk allowance.
        $limit = (int) config('crm.api.rate_limits.bulk');

        for ($i = 0; $i < $limit; $i++) {
            $this->getJson("/api/v1/campaigns/{$campaign->id}/preview")->assertOk();
        }

        $this->getJson("/api/v1/campaigns/{$campaign->id}/preview")->assertStatus(429);
    }

    /** The throttle limiters a named route actually carries, e.g. ['api-standard']. */
    private function limitersOn(string $routeName): array
    {
        $route = Route::getRoutes()->getByName($routeName);
        $this->assertNotNull($route, "Route {$routeName} does not exist.");

        return collect($route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'))
            ->map(fn (string $middleware) => substr($middleware, strlen('throttle:')))
            ->values()
            ->all();
    }

    #[Test]
    public function ordinary_campaign_reads_stay_on_the_standard_allowance(): void
    {
        $this->actingAsAdmin();

        // Listing campaigns is normal authenticated traffic. Throttling a
        // dashboard poll at ten a minute would push operators to reload harder,
        // which is the opposite of what the limiter is for.
        $limit = (int) config('crm.api.rate_limits.bulk');

        for ($i = 0; $i <= $limit; $i++) {
            $this->getJson('/api/v1/campaigns')->assertOk();
        }

        // Asserted on the route as well as through it. Surviving more than ten
        // requests is also what a route with NO limiter does - which is exactly
        // the bug - so the behaviour alone cannot tell the fix from the defect.
        $this->assertSame(['api-standard'], $this->limitersOn('api.v1.campaigns.index'));
    }

    #[Test]
    public function stopping_a_runaway_campaign_is_never_harder_than_starting_it(): void
    {
        // The brakes stay on the standard allowance on purpose. A start is
        // bulk-limited because it fans out per lead; if stop shared that
        // allowance, the operator who fired ten starts would find themselves
        // unable to stop any of them - a limiter pointed the wrong way.
        $this->assertSame(['api-standard', 'api-bulk'], $this->limitersOn('api.v1.campaigns.start'));
        $this->assertSame(['api-standard'], $this->limitersOn('api.v1.campaigns.stop'));
        $this->assertSame(['api-standard'], $this->limitersOn('api.v1.campaigns.pause'));
    }

    #[Test]
    public function every_campaign_route_carries_a_rate_limiter(): void
    {
        $unlimited = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/v1/campaigns'))
            ->reject(fn (RoutingRoute $route) => collect($route->gatherMiddleware())
                ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:')))
            ->map(fn (RoutingRoute $route) => $route->methods()[0].' '.$route->uri())
            ->values();

        // NFR-07 is a property of the API surface, not of the endpoints anybody
        // remembered. One unlimited route in a group is the one an attacker
        // finds.
        $this->assertSame([], $unlimited->all());
    }
}
