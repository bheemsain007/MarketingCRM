<?php

namespace Tests\Feature\Api;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API contract: the response envelope (NFR-03).
 *
 * The envelope is what both the Web CRM and the Flutter app parse. If an error
 * path ever returns a different shape, every client breaks at exactly the moment
 * something has already gone wrong - so error paths are tested here as heavily
 * as success paths.
 */
class ResponseEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    /** Routes exist only for the duration of a test. */
    private function fakeRoute(string $uri, callable $handler): void
    {
        Route::middleware('api')->get('api/v1/'.$uri, $handler);
    }

    #[Test]
    public function a_success_response_uses_the_documented_envelope(): void
    {
        $this->fakeRoute('t-success', fn () => ApiResponse::success(['id' => 1], 'Fetched.'));

        $this->getJson('/api/v1/t-success')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'errors'])
            ->assertJson([
                'success' => true,
                'message' => 'Fetched.',
                'data' => ['id' => 1],
                'errors' => [],
            ]);
    }

    #[Test]
    public function an_error_response_uses_the_same_envelope(): void
    {
        $this->fakeRoute('t-error', fn () => throw new ApiException(ErrorCode::Conflict));

        $response = $this->getJson('/api/v1/t-error')->assertStatus(409);

        $response->assertJsonStructure(['success', 'message', 'data', 'errors'])
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.code', 'resource.conflict');
    }

    #[Test]
    public function empty_data_serialises_as_an_object_not_an_array(): void
    {
        // A client typed against an object breaks when [] arrives instead of {}.
        $this->fakeRoute('t-empty', fn () => ApiResponse::success([]));

        $content = $this->getJson('/api/v1/t-empty')->assertOk()->content();

        $this->assertStringContainsString('"data":{}', $content);
        $this->assertStringNotContainsString('"data":[]', $content);
    }

    #[Test]
    public function created_returns_201(): void
    {
        $this->fakeRoute('t-created', fn () => ApiResponse::created(['id' => 7]));

        $this->getJson('/api/v1/t-created')
            ->assertStatus(201)
            ->assertJsonPath('data.id', 7);
    }

    #[Test]
    public function accepted_returns_202_for_async_work(): void
    {
        // Bulk endpoints must never block a request (NFR-06); they return 202
        // plus a reference to poll.
        $this->fakeRoute('t-accepted', fn () => ApiResponse::accepted(['batch_id' => 'abc']));

        $this->getJson('/api/v1/t-accepted')
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.batch_id', 'abc');
    }

    #[Test]
    public function unknown_routes_return_the_envelope_not_an_html_page(): void
    {
        $response = $this->getJson('/api/v1/definitely-not-a-route');

        $response->assertStatus(404)
            ->assertJsonStructure(['success', 'message', 'data', 'errors'])
            ->assertJsonPath('errors.0.code', 'resource.not_found');
    }

    #[Test]
    public function validation_failures_are_reported_per_field(): void
    {
        $this->fakeRoute('t-validate', function () {
            request()->validate(['name' => 'required', 'email' => 'required|email']);
        });

        $response = $this->getJson('/api/v1/t-validate?email=not-an-email')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.code', 'validation.failed');

        $fields = array_column($response->json('errors'), 'field');

        $this->assertContains('name', $fields);
        $this->assertContains('email', $fields);
    }

    #[Test]
    public function server_errors_never_leak_internals_in_production_mode(): void
    {
        // SEC-OPS-03 / NFR-08: a stack trace or SQL string in an API response is
        // an information-disclosure bug.
        config(['app.debug' => false]);

        $this->fakeRoute('t-boom', fn () => throw new \RuntimeException('SQLSTATE secret table detail'));

        $response = $this->getJson('/api/v1/t-boom')->assertStatus(500);

        $this->assertSame(ErrorCode::ServerError->defaultMessage(), $response->json('message'));
        $this->assertStringNotContainsString('SQLSTATE', $response->content());
        $this->assertStringNotContainsString('secret table detail', $response->content());
    }

    #[Test]
    public function a_domain_exception_carries_its_context_to_the_client(): void
    {
        // The UI needs to know WHY an action was refused, not just that it was.
        $this->fakeRoute('t-domain', fn () => throw new ApiException(
            ErrorCode::LeadDuplicate,
            context: ['existing_lead_id' => 42],
        ));

        $this->getJson('/api/v1/t-domain')
            ->assertStatus(409)
            ->assertJsonPath('data.existing_lead_id', 42)
            ->assertJsonPath('errors.0.code', 'lead.duplicate');
    }
}
