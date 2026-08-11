<?php

namespace Tests\Feature\Api;

use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rate limiting (NFR-07, API_DOCUMENTATION §7).
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exceeding_the_bulk_limit_returns_429_in_the_standard_envelope(): void
    {
        Route::middleware(['api', 'throttle:api-bulk'])
            ->get('api/v1/t-bulk', fn () => ApiResponse::success());

        $limit = (int) config('crm.api.rate_limits.bulk');

        for ($i = 0; $i < $limit; $i++) {
            $this->getJson('/api/v1/t-bulk')->assertOk();
        }

        $this->getJson('/api/v1/t-bulk')
            ->assertStatus(429)
            ->assertJsonStructure(['success', 'message', 'data', 'errors'])
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.code', 'rate_limit.exceeded');
    }

    #[Test]
    public function a_throttled_response_tells_the_client_when_to_retry(): void
    {
        Route::middleware(['api', 'throttle:api-bulk'])
            ->get('api/v1/t-bulk-retry', fn () => ApiResponse::success());

        $limit = (int) config('crm.api.rate_limits.bulk');

        for ($i = 0; $i < $limit; $i++) {
            $this->getJson('/api/v1/t-bulk-retry');
        }

        $response = $this->getJson('/api/v1/t-bulk-retry')->assertStatus(429);

        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    #[Test]
    public function bulk_endpoints_are_limited_far_below_normal_traffic(): void
    {
        // Bulk triggers fan out into thousands of queued jobs, so they must not
        // share the standard allowance.
        $this->assertLessThan(
            (int) config('crm.api.rate_limits.standard'),
            (int) config('crm.api.rate_limits.bulk'),
        );
    }

    #[Test]
    public function webhook_limits_are_generous_enough_not_to_reject_provider_retries(): void
    {
        // SEC-WH-05: throttling a valid provider retry loses delivery receipts
        // and inbound leads - worse than the traffic it would prevent.
        $this->assertGreaterThan(
            (int) config('crm.api.rate_limits.standard'),
            (int) config('crm.api.rate_limits.webhook'),
        );
    }
}
