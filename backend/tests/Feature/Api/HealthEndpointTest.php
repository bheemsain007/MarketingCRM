<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_health_endpoint_reports_ok_when_dependencies_are_up(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database', true);
    }

    #[Test]
    public function the_health_endpoint_is_reachable_without_authentication(): void
    {
        // An uptime monitor cannot hold credentials - which is also why the
        // endpoint returns no business data.
        $this->getJson('/api/v1/health')->assertOk();
    }

    #[Test]
    public function it_is_mounted_under_the_versioned_prefix(): void
    {
        // NFR-02: all project endpoints live under /api/v1.
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/health')->assertStatus(404);
    }

    #[Test]
    public function every_response_carries_a_correlation_id(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertNotEmpty($response->headers->get(AssignCorrelationId::HEADER));
    }

    #[Test]
    public function an_inbound_correlation_id_is_preserved(): void
    {
        // Lets the Flutter app or a load balancer originate the trace, so one ID
        // spans client, API, queued job and provider call.
        $response = $this->getJson('/api/v1/health', [
            AssignCorrelationId::HEADER => 'trace-abc-123',
        ])->assertOk();

        $this->assertSame('trace-abc-123', $response->headers->get(AssignCorrelationId::HEADER));
    }
}
