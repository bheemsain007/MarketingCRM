<?php

namespace Tests\Feature\Messaging;

use App\Enums\Channel;
use App\Enums\RoleName;
use App\Jobs\SendMessage;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use App\Services\Dnc\DncService;
use App\Services\Messaging\MessageDriverManager;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WhatsApp's 24-hour customer-service window (FR-WA-01).
 *
 * Meta accepts a free-form `text` message only while the window the LEAD's own
 * last inbound message opened is still open; outside it, only a message using a
 * template Meta itself has approved is accepted, and everything else is an
 * ordinary 4xx with no signal an operator can act on. `WhatsAppDriver` now
 * decides this itself before ever calling Meta, so these tests prove: a fresh
 * lead who never messaged in only gets a `text` send when the window is open, a
 * template send outside it goes as `type: template` with the provider's own
 * registered name/language/parameters, and a send outside the window with
 * nothing approved refuses cleanly rather than guessing.
 */
class WhatsAppTemplateWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Http::preventStrayRequests();
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Admin->value)->first());
        $this->actingAs($user->fresh(), 'sanctum');

        return $user->fresh();
    }

    private function configureWhatsApp(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('providers.whatsapp.token', 'EAAG-cloud-api-token');
        $settings->set('providers.whatsapp.phone_number_id', '10987654321');
    }

    private function runJob(Message $message): void
    {
        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );
    }

    private function approvedTemplate(array $overrides = []): Template
    {
        return Template::factory()->channel(Channel::WhatsApp)->create(array_merge([
            'approval_status' => 'approved',
            'provider_template_id' => 'shipping_update_v1',
            'body' => 'Hi {{ lead_name }}, your order from {{ organisation }} has shipped.',
            'variables' => ['lead_name', 'organisation'],
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // Outside the window
    // -----------------------------------------------------------------------

    #[Test]
    public function outside_the_window_an_approved_template_is_sent_as_a_provider_template(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        app(SettingsService::class)->set('providers.whatsapp.template_language', 'en_US');

        // No inbound message at all - a fresh lead has never opened the window.
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210', 'name' => 'Ramesh Kumar']);
        $template = $this->approvedTemplate();

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value,
            'template_id' => $template->id,
        ])->assertStatus(202);

        $message = Message::where('lead_id', $lead->id)->where('direction', 'outbound')->firstOrFail();

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL1']]], 200)]);

        $this->runJob($message);

        Http::assertSent(function ($request) {
            return $request['type'] === 'template'
                && ! array_key_exists('text', $request->data())
                && $request['template']['name'] === 'shipping_update_v1'
                && $request['template']['language']['code'] === 'en_US'
                // Positional parameters, in the template's own declared order -
                // the same values the preview/render path resolves, reshaped
                // for the wire rather than substituted into free text.
                && $request['template']['components'][0]['type'] === 'body'
                && $request['template']['components'][0]['parameters'][0]['text'] === 'Ramesh Kumar'
                && $request['template']['components'][0]['parameters'][1]['text'] === config('app.name');
        });

        $this->assertSame('sent', $message->fresh()->status);
        $this->assertSame('wamid.TPL1', $message->fresh()->provider_message_id);
    }

    #[Test]
    public function outside_the_window_with_no_template_attached_the_send_refuses_without_calling_meta(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        app(SettingsService::class)->set('providers.whatsapp.template_language', 'en_US');

        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value,
            'body' => 'Free-form text with nothing approved behind it.',
        ])->assertStatus(202);

        $message = Message::where('lead_id', $lead->id)->where('direction', 'outbound')->firstOrFail();

        // Http::preventStrayRequests() means an unexpected call to Meta fails
        // the test outright - the refusal must happen before any HTTP call.
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('pre-approved template', (string) $message->failure_reason);
        $this->assertStringContainsString('24-hour', (string) $message->failure_reason);
    }

    #[Test]
    public function outside_the_window_a_template_not_approved_at_the_provider_refuses_cleanly(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        app(SettingsService::class)->set('providers.whatsapp.template_language', 'en_US');

        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);
        // Never registered with Meta - a WhatsApp template is never approved
        // locally (T-31), so this stays "draft" forever until it is.
        $template = Template::factory()->channel(Channel::WhatsApp)->create([
            'approval_status' => 'draft',
        ]);

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value,
            'template_id' => $template->id,
        ])->assertStatus(202);

        $message = Message::where('lead_id', $lead->id)->where('direction', 'outbound')->firstOrFail();

        $this->runJob($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('draft', (string) $message->failure_reason);
    }

    #[Test]
    public function outside_the_window_a_missing_template_language_setting_refuses_cleanly(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        // providers.whatsapp.template_language deliberately left unset.

        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);
        $template = $this->approvedTemplate();

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value,
            'template_id' => $template->id,
        ])->assertStatus(202);

        $message = Message::where('lead_id', $lead->id)->where('direction', 'outbound')->firstOrFail();

        $this->runJob($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('providers.whatsapp.template_language', (string) $message->failure_reason);
    }

    // -----------------------------------------------------------------------
    // Inside the window - unchanged (regression)
    // -----------------------------------------------------------------------

    #[Test]
    public function inside_the_window_a_send_still_goes_as_free_text_even_with_an_approved_template_on_hand(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        // The lead messaged in recently - the window is open, so a template
        // being available changes nothing about this send.
        Message::factory()->create([
            'lead_id' => $lead->id,
            'channel' => Channel::WhatsApp->value,
            'direction' => 'inbound',
            'status' => 'received',
            'recipient' => $lead->phone_e164,
            'body' => 'Yes, go ahead.',
            'sent_at' => now()->subHour(),
        ]);
        $this->approvedTemplate();

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value,
            'body' => 'Your order has shipped.',
        ])->assertStatus(202);

        $message = Message::where('lead_id', $lead->id)->where('direction', 'outbound')->firstOrFail();

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TXT1']]], 200)]);

        $this->runJob($message);

        Http::assertSent(fn ($request) => $request['type'] === 'text'
            && $request['text']['body'] === 'Your order has shipped.');

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function a_message_older_than_24_hours_no_longer_counts_as_open(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        app(SettingsService::class)->set('providers.whatsapp.template_language', 'en_US');

        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        // Just past the boundary - the window has closed.
        Message::factory()->create([
            'lead_id' => $lead->id,
            'channel' => Channel::WhatsApp->value,
            'direction' => 'inbound',
            'status' => 'received',
            'recipient' => $lead->phone_e164,
            'body' => 'An old reply.',
            'sent_at' => now()->subHours(25),
        ]);

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value,
            'body' => 'Still just free text.',
        ])->assertStatus(202);

        $message = Message::where('lead_id', $lead->id)->where('direction', 'outbound')->firstOrFail();

        $this->runJob($message);

        // No template attached and the window has closed - refused, not sent as text.
        $this->assertSame('failed', $message->fresh()->status);
    }
}
