<?php

namespace Tests\Feature\Web;

use App\Enums\DataScope;
use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two cross-lead history screens (FR-CALL-05, FR-COMM-05).
 *
 * Both are shells: the rows arrive from `/api/v1/calls` and `/api/v1/messages`,
 * and the scoping that keeps one telecaller out of another's book lives there
 * (CallHistoryTest covers it). What is worth asserting here is that the shells
 * are gated on the right permission, that navigation does not offer a page the
 * route would refuse, and that the reference data each page renders comes from
 * the enum rather than being retyped into the markup.
 *
 * Neither page depends on the clock, so no time is frozen.
 */
class HistoryScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** @param array<string, mixed> $attributes */
    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    /**
     * A signed-in user who holds no permissions at all.
     *
     * Every seeded role has `leads.view` - the message screen's gate - so the
     * negative case for it needs a role built for the purpose. Without one,
     * "the gate is the gate" is asserted for calls and merely assumed for
     * messages.
     */
    private function permissionlessUser(): User
    {
        $role = Role::create([
            'tenant_id' => 0,
            'name' => 'no_access',
            'label' => 'No Access',
            'data_scope' => DataScope::Own->value,
            'is_system' => false,
        ]);

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->fresh();
    }

    // -----------------------------------------------------------------------
    // Call history
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_can_open_the_call_history(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/calls')
            ->assertOk()
            ->assertSee('Who called')
            ->assertSee('Any outcome')
            ->assertSee('/api/v1/calls', false);
    }

    #[Test]
    public function a_role_without_calls_view_cannot_open_the_call_history(): void
    {
        // Accounts works on payments and holds no call permission at all.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/calls')
            ->assertStatus(403);
    }

    #[Test]
    public function navigation_shows_call_history_only_to_those_who_may_see_calls(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/calls"', false);

        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/calls"', false);
    }

    #[Test]
    public function the_call_history_offers_every_outcome_the_api_records(): void
    {
        // Rendered from CallStatus::cases(), so a new outcome cannot become a
        // filter the screen quietly stops offering.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/calls')
            ->assertOk()
            ->assertSee('<option value="call_back_requested">Call Back Requested</option>', false)
            ->assertSee('<option value="wrong_number">Wrong Number</option>', false);
    }

    #[Test]
    public function the_call_history_offers_no_write_control(): void
    {
        /*
         * It is a log. Recording an outcome belongs to the person who made the
         * call (CallPolicy::recordOutcome), and there is no lead in front of
         * you here to dial - a button either way would only ever 403.
         */
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/calls')
            ->assertOk()
            ->assertDontSee('Log a call')
            ->assertDontSee('method: \'PATCH\'', false)
            ->assertDontSee('method: \'POST\'', false);
    }

    // -----------------------------------------------------------------------
    // Message history
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_can_open_the_message_history(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/messages')
            ->assertOk()
            ->assertSee('Delivery state')
            ->assertSee('Any channel')
            ->assertSee('/api/v1/messages', false);
    }

    #[Test]
    public function a_user_without_leads_view_cannot_open_the_message_history(): void
    {
        // Gated on leads.view, matching the API behind it: message history is
        // lead data and there is no `messages.view` in the permission model.
        $this->actingAs($this->permissionlessUser())
            ->get('/messages')
            ->assertStatus(403);
    }

    #[Test]
    public function navigation_shows_messages_only_to_those_who_may_see_leads(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/messages"', false);

        $this->actingAs($this->permissionlessUser())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/messages"', false);
    }

    #[Test]
    public function the_message_history_offers_every_channel_the_api_records(): void
    {
        // Rendered from Channel::cases(). Voice channels are included on
        // purpose here, unlike the template form: a campaign voice send is
        // recorded as a Message and would otherwise be unfilterable.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/messages')
            ->assertOk()
            ->assertSee('<option value="whatsapp">WhatsApp</option>', false)
            ->assertSee('<option value="ai_call">AI Call</option>', false);
    }

    #[Test]
    public function the_message_history_names_the_suppressed_state_it_can_show(): void
    {
        /*
         * A send refused by the do-not-contact list is recorded as `skipped`
         * (BR-DNC-05). If the screen could not filter to it, the audit trail
         * would exist in the database and nowhere a person looks.
         */
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/messages')
            ->assertOk()
            ->assertSee('<option value="skipped">', false)
            ->assertSee('do-not-contact list stopped the send', false);
    }

    #[Test]
    public function the_message_history_offers_no_send_control(): void
    {
        // Sending happens against a lead, where the DNC gate and the channel
        // rules live. This screen is the record of what already went out.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/messages')
            ->assertOk()
            ->assertDontSee('Send message')
            ->assertDontSee('method: \'POST\'', false);
    }
}
