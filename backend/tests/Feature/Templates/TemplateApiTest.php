<?php

namespace Tests\Feature\Templates;

use App\Enums\Channel;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Template management API (FR-COMM-02, T-31).
 *
 * The table and the model shipped in Phase 2 and messages have accepted a
 * template_id since Phase 13, but there was no way to author one - so these
 * tests are the first that exercise a template end to end.
 */
class TemplateApiTest extends TestCase
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

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Diwali Offer 2026',
            'channel' => Channel::Email->value,
            'subject' => 'A Diwali gift for {{ lead_name }}',
            'body' => 'Hi {{ lead_name }}, {{ organisation }} has something for you.',
            'variables' => ['lead_name', 'organisation'],
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------------

    #[Test]
    public function an_admin_can_create_a_template(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/templates', $this->validPayload(['code' => 'DIWALI_2026']))
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'DIWALI_2026')
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('templates', [
            'code' => 'DIWALI_2026',
            'tenant_id' => 0,
        ]);
    }

    #[Test]
    public function a_code_is_derived_from_the_name_when_the_caller_supplies_none(): void
    {
        // A template is authored in a UI that has a name field and no code
        // field; requiring a code would make the most common create call fail.
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/templates', $this->validPayload())
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'DIWALI_OFFER_2026');
    }

    #[Test]
    public function a_derived_code_steps_around_a_collision_instead_of_failing(): void
    {
        // The caller never chose this code, so a clash is not their mistake to
        // fix - unlike a code they supplied, which 409s.
        $this->actingAsRole(RoleName::Admin);
        Template::factory()->create(['code' => 'DIWALI_OFFER_2026']);

        $this->postJson('/api/v1/templates', $this->validPayload())
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'DIWALI_OFFER_2026_2');
    }

    #[Test]
    public function a_duplicate_code_returns_a_clean_conflict_not_a_database_error(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $existing = Template::factory()->create(['code' => 'TAKEN']);

        $this->postJson('/api/v1/templates', $this->validPayload(['code' => 'TAKEN']))
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'resource.conflict')
            ->assertJsonPath('data.existing_template_id', $existing->id);
    }

    #[Test]
    public function a_code_held_by_a_retired_template_is_reported_as_such(): void
    {
        // Otherwise the caller is told a code is taken by a template that does
        // not appear anywhere in their list.
        $this->actingAsRole(RoleName::Admin);
        Template::factory()->inactive()->create(['code' => 'OLD_ONE']);

        $this->postJson('/api/v1/templates', $this->validPayload(['code' => 'OLD_ONE']))
            ->assertStatus(409)
            ->assertJsonPath('data.is_active', false);
    }

    #[Test]
    public function creating_a_template_validates_the_required_fields(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $response = $this->postJson('/api/v1/templates', [])->assertStatus(422);

        $fields = array_column($response->json('errors'), 'field');
        $this->assertContains('name', $fields);
        $this->assertContains('channel', $fields);
        $this->assertContains('body', $fields);
    }

    // -----------------------------------------------------------------------
    // Channel rules
    // -----------------------------------------------------------------------

    #[Test]
    public function the_calling_channels_are_refused(): void
    {
        // `call` and `ai_call` place a call rather than sending a message, and
        // nothing in the send path reads a template for them - a template on
        // one of them would look usable and never be sendable.
        $this->actingAsRole(RoleName::Admin);

        foreach ([Channel::Call, Channel::AiCall] as $channel) {
            $response = $this->postJson('/api/v1/templates', $this->validPayload([
                'channel' => $channel->value,
                'subject' => null,
            ]))->assertStatus(422);

            $this->assertContains('channel', array_column($response->json('errors'), 'field'));
        }
    }

    #[Test]
    public function an_unknown_channel_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/templates', $this->validPayload([
            'channel' => 'carrier_pigeon',
            'subject' => null,
        ]))->assertStatus(422);
    }

    #[Test]
    public function only_an_email_template_may_carry_a_subject(): void
    {
        // Exactly the rule StoreMessageRequest applies to messages. If the two
        // disagreed, an operator could author a headline on an SMS template
        // that the send path silently discards.
        $this->actingAsRole(RoleName::Admin);

        $response = $this->postJson('/api/v1/templates', $this->validPayload([
            'channel' => Channel::Sms->value,
            'subject' => 'Not allowed here',
        ]))->assertStatus(422);

        $this->assertContains('subject', array_column($response->json('errors'), 'field'));

        // The same payload on email is fine.
        $this->postJson('/api/v1/templates', $this->validPayload([
            'code' => 'EMAIL_ONE',
            'channel' => Channel::Email->value,
        ]))->assertStatus(201);
    }

    #[Test]
    public function a_subject_cannot_be_patched_onto_a_non_email_template(): void
    {
        // The update path judges the EFFECTIVE channel, not the submitted one -
        // otherwise the rule above could be walked around one field at a time.
        $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->channel(Channel::Sms)->create();

        $this->patchJson("/api/v1/templates/{$template->id}", ['subject' => 'Sneaked in'])
            ->assertStatus(422);
    }

    #[Test]
    public function moving_a_template_off_email_drops_its_subject(): void
    {
        // A subject that no channel will ever deliver is worse than none: it
        // reads back as though it were sent.
        $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->create(['subject' => 'Headline']);

        $this->patchJson("/api/v1/templates/{$template->id}", ['channel' => Channel::Sms->value])
            ->assertOk()
            ->assertJsonPath('data.subject', null);
    }

    // -----------------------------------------------------------------------
    // Approval status (T-31)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_template_on_a_local_channel_is_approved_on_save(): void
    {
        // Email, SMS and voice have no external approver, so leaving them at
        // draft would make isSendable() false forever.
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/templates', $this->validPayload())
            ->assertStatus(201)
            ->assertJsonPath('data.approval_status', 'approved')
            ->assertJsonPath('data.is_sendable', true);
    }

    #[Test]
    public function a_whatsapp_template_is_never_approved_locally(): void
    {
        // Approval there is Meta's to give. Marking it approved here produces a
        // template that fails at send time, after the operator has been told it
        // was ready.
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/templates', $this->validPayload([
            'channel' => Channel::WhatsApp->value,
            'subject' => null,
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.approval_status', 'draft')
            ->assertJsonPath('data.is_sendable', false);
    }

    #[Test]
    public function a_whatsapp_template_already_registered_at_the_provider_starts_pending(): void
    {
        // It exists at Meta - which is a different state from "never submitted"
        // - but nothing here has seen their decision.
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/templates', $this->validPayload([
            'channel' => Channel::WhatsApp->value,
            'subject' => null,
            'provider' => 'meta',
            'provider_template_id' => 'diwali_offer_v3',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.approval_status', 'pending');
    }

    #[Test]
    public function editing_an_approved_provider_template_returns_it_to_draft(): void
    {
        // The provider approved the text it was shown. Without this, the edit
        // box is a way to send arbitrary content under an approved template id.
        $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->channel(Channel::WhatsApp)->create([
            'approval_status' => 'approved',
            'provider_template_id' => 'offer_v1',
        ]);

        $this->patchJson("/api/v1/templates/{$template->id}", ['body' => 'Completely different text'])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'draft');
    }

    #[Test]
    public function renaming_an_approved_provider_template_leaves_the_approval_alone(): void
    {
        // The provider reviewed the content, not the internal label.
        $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->channel(Channel::WhatsApp)->create([
            'approval_status' => 'approved',
        ]);

        $this->patchJson("/api/v1/templates/{$template->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');
    }

    #[Test]
    public function approval_status_cannot_be_set_by_hand(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/templates', $this->validPayload([
            'channel' => Channel::WhatsApp->value,
            'subject' => null,
            'approval_status' => 'approved',
        ]))->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------------

    #[Test]
    public function the_list_uses_the_standard_envelope_and_pagination(): void
    {
        Template::factory()->count(7)->create();
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/templates?per_page=3')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['items', 'meta'], 'errors'])
            ->assertJsonPath('data.meta.total', 7)
            ->assertJsonCount(3, 'data.items');
    }

    #[Test]
    public function templates_can_be_filtered_by_channel(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Template::factory()->count(2)->create();
        Template::factory()->channel(Channel::Sms)->create();

        $this->getJson('/api/v1/templates?filter[channel]=sms')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function templates_are_searchable_by_name_and_code(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Template::factory()->create(['name' => 'Monsoon Reminder', 'code' => 'MONSOON_1']);
        Template::factory()->create(['name' => 'Unrelated', 'code' => 'WELCOME_MAIL']);

        $this->getJson('/api/v1/templates?q=Monsoon')
            ->assertOk()->assertJsonPath('data.meta.total', 1);

        // An operator who remembers the code and not the name gets the same hit.
        $this->getJson('/api/v1/templates?q=WELCOME')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.code', 'WELCOME_MAIL');
    }

    #[Test]
    public function retired_templates_are_hidden_until_they_are_asked_for(): void
    {
        // Hiding them is the whole effect of retiring one; the explicit filter
        // is how a manager finds one in order to restore it.
        $this->actingAsRole(RoleName::Admin);
        Template::factory()->count(2)->create();
        Template::factory()->inactive()->create();

        $this->getJson('/api/v1/templates')->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/templates?with_inactive=1')->assertOk()->assertJsonPath('data.meta.total', 3);
        $this->getJson('/api/v1/templates?filter[is_active]=0')->assertOk()->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function an_unknown_filter_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/templates?filter[secret]=1')->assertStatus(422);
    }

    #[Test]
    public function a_single_template_can_be_read(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->create(['name' => 'Welcome']);

        $this->getJson("/api/v1/templates/{$template->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Welcome')
            ->assertJsonPath('data.message_count', 0);
    }

    #[Test]
    public function requesting_a_missing_template_returns_the_envelope(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/templates/999999')
            ->assertStatus(404)
            ->assertJsonPath('errors.0.code', 'resource.not_found');
    }

    // -----------------------------------------------------------------------
    // Updating
    // -----------------------------------------------------------------------

    #[Test]
    public function updating_a_template_records_the_editor(): void
    {
        $user = $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->create(['name' => 'Before']);

        $this->patchJson("/api/v1/templates/{$template->id}", ['name' => 'After'])
            ->assertOk()
            ->assertJsonPath('data.name', 'After');

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'updated_by' => $user->id]);
    }

    #[Test]
    public function an_update_cannot_take_a_code_another_template_holds(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Template::factory()->create(['code' => 'MINE']);
        $other = Template::factory()->create(['code' => 'OTHER']);

        $this->patchJson("/api/v1/templates/{$other->id}", ['code' => 'MINE'])
            ->assertStatus(409);
    }

    // -----------------------------------------------------------------------
    // Retiring (DELETE) - the rule that history must survive
    // -----------------------------------------------------------------------

    #[Test]
    public function deleting_a_template_deactivates_it_rather_than_destroying_it(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->create();

        $this->deleteJson("/api/v1/templates/{$template->id}")->assertOk();

        // Present, and not soft-deleted either: a trashed template would make
        // the message->template relation resolve to null.
        $this->assertDatabaseHas('templates', ['id' => $template->id, 'is_active' => false]);
        $this->assertNotSoftDeleted('templates', ['id' => $template->id]);
    }

    #[Test]
    public function a_sent_message_still_resolves_its_template_after_it_is_retired(): void
    {
        /*
         * This is what "must not vanish from their history" actually means. The
         * message history eager-loads `template:id,name,code`, and a soft delete
         * would satisfy the database while resolving that relation to null - a
         * message sent last month would display with no template at all.
         *
         * Asserted through the API rather than the model, because the eager load
         * is where a soft delete would silently bite.
         */
        $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->create(['name' => 'Old Campaign Text']);
        $lead = Lead::factory()->create();
        Message::factory()->sent()->create(['lead_id' => $lead->id, 'template_id' => $template->id]);

        $this->deleteJson("/api/v1/templates/{$template->id}")->assertOk();

        $this->getJson("/api/v1/messages?filter[lead_id]={$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.template.name', 'Old Campaign Text');
    }

    #[Test]
    public function a_retired_template_can_be_restored(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->inactive()->create();

        $this->postJson("/api/v1/templates/{$template->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    // -----------------------------------------------------------------------
    // Preview (FR-COMM-02) - one renderer, and it is not Blade
    // -----------------------------------------------------------------------

    #[Test]
    public function a_preview_substitutes_tokens_against_a_real_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['name' => 'Ramesh Kumar', 'email' => 'ramesh@example.com']);
        $template = Template::factory()->create([
            'subject' => 'Hello {{ lead_name }}',
            'body' => 'Hi {{ lead_name }}, welcome to {{ organisation }}.',
        ]);

        $this->getJson("/api/v1/templates/{$template->id}/preview?lead_id={$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.subject', 'Hello Ramesh Kumar')
            ->assertJsonPath('data.body', 'Hi Ramesh Kumar, welcome to '.config('app.name').'.')
            // The address the send would actually use, so "no email on record"
            // is visible before pressing send rather than after.
            ->assertJsonPath('data.recipient', 'ramesh@example.com');
    }

    #[Test]
    public function a_preview_never_executes_blade(): void
    {
        // A template body is operator-supplied text. Rendering it as Blade would
        // make the editor a remote code execution primitive for anyone holding
        // templates.manage (SEC-IN-06), and the preview must not be a second
        // renderer that reintroduces that.
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['name' => 'Ramesh Kumar']);
        $template = Template::factory()->create([
            'subject' => null,
            'channel' => Channel::Sms->value,
            'body' => 'Hi {{ lead_name }}, {{ 2+2 }} {{ config("app.key") }}',
        ]);

        $body = $this->getJson("/api/v1/templates/{$template->id}/preview?lead_id={$lead->id}")
            ->assertOk()
            ->json('data.body');

        $this->assertStringContainsString('Hi Ramesh Kumar', $body);
        $this->assertStringContainsString('{{ 2+2 }}', $body);
        $this->assertStringNotContainsString('base64:', $body);
    }

    #[Test]
    public function a_preview_requires_a_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $template = Template::factory()->create();

        $this->getJson("/api/v1/templates/{$template->id}/preview")->assertStatus(422);
    }

    #[Test]
    public function a_preview_cannot_be_used_to_read_someone_elses_lead(): void
    {
        // Rendering puts the lead's name, company and city in the response, so
        // an unscoped preview is a PII read - and templates.view is broad
        // enough that every telecaller holds it (SEC-AUTHZ-04).
        $owner = $this->actingAsRole(RoleName::Telecaller);
        $theirLead = Lead::factory()->create(['assigned_to' => User::factory()->create()->id]);
        $myLead = Lead::factory()->create(['assigned_to' => $owner->id]);
        $template = Template::factory()->create();

        $this->getJson("/api/v1/templates/{$template->id}/preview?lead_id={$theirLead->id}")
            ->assertStatus(403);

        $this->getJson("/api/v1/templates/{$template->id}/preview?lead_id={$myLead->id}")
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Permissions - both sides of every route
    // -----------------------------------------------------------------------

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/templates')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'auth.unauthenticated');
    }

    #[Test]
    public function a_telecaller_may_read_templates(): void
    {
        // They pick a template every time they send, so the read gate is broad.
        $me = $this->actingAsRole(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $me->id]);
        $template = Template::factory()->create();

        $this->getJson('/api/v1/templates')->assertOk();
        $this->getJson("/api/v1/templates/{$template->id}")->assertOk();
        $this->getJson("/api/v1/templates/{$template->id}/preview?lead_id={$lead->id}")->assertOk();
    }

    #[Test]
    public function a_telecaller_may_not_author_or_retire_templates(): void
    {
        // The other side of the same role: authoring is deciding what the
        // organisation says in its own name to thousands of people at once.
        $this->actingAsRole(RoleName::Telecaller);
        $template = Template::factory()->create();

        $this->postJson('/api/v1/templates', $this->validPayload())
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'auth.forbidden');

        $this->patchJson("/api/v1/templates/{$template->id}", ['name' => 'Changed'])->assertStatus(403);
        $this->deleteJson("/api/v1/templates/{$template->id}")->assertStatus(403);
        $this->postJson("/api/v1/templates/{$template->id}/restore")->assertStatus(403);

        // Nothing changed as a result.
        $this->assertDatabaseHas('templates', ['id' => $template->id, 'is_active' => true]);
    }

    #[Test]
    public function a_role_without_templates_view_cannot_even_list_them(): void
    {
        // Accounts works on money, not messaging, and holds neither permission.
        $this->actingAsRole(RoleName::Accounts);
        $template = Template::factory()->create();

        $this->getJson('/api/v1/templates')->assertStatus(403);
        $this->getJson("/api/v1/templates/{$template->id}")->assertStatus(403);
    }

    #[Test]
    public function a_manager_may_author_templates(): void
    {
        // Managers own campaign content (Permission::defaultsFor).
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/templates', $this->validPayload(['code' => 'MANAGER_MADE']))
            ->assertStatus(201);
    }
}
