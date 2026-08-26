<?php

namespace Tests\Feature\Web;

use App\Enums\Channel;
use App\Enums\DataScope;
use App\Enums\DialerSkipReason;
use App\Enums\DncReason;
use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use App\Models\AutoDialerQueueItem;
use App\Models\AutoDialerSession;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two suppression screens (FR-DNC-01, BR-DNC-05, BR-DNC-06).
 *
 * Both are shells (ADR-A), so what is worth asserting is who can reach them and
 * which controls they are drawn - plus, for the skip log, that the endpoint the
 * page reads actually returns the attempts it claims to: the log unions two
 * tables, and a screen pointed at a broken query looks exactly like a quiet
 * month.
 */
class DncScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // The skip log defaults to "this month" (ReportPeriod). Run at midnight
        // on the 1st, an unfrozen clock decides whether the fixtures fall in the
        // window - a test that can flake on the calendar is a bug.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    /**
     * A user who may READ the suppression list but not add to it.
     *
     * No seeded role is shaped that way - every role with `dnc.view` also holds
     * `dnc.create` - so the negative case needs a role built for it. Without
     * one, "the control is hidden when you cannot use it" is untested.
     */
    private function readOnlyDncUser(): User
    {
        $role = Role::create([
            'tenant_id' => 0,
            'name' => 'dnc_reader',
            'label' => 'DNC Reader',
            'data_scope' => DataScope::All->value,
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::where('name', PermissionEnum::DncView->value)->first(),
        );

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->fresh();
    }

    private function skippedMessage(Lead $lead, string $reason = 'suppressed'): Message
    {
        return Message::factory()->for($lead)->skipped($reason)->create([
            'channel' => Channel::Sms->value,
            'created_at' => now(),
        ]);
    }

    private function skippedCall(Lead $lead, User $caller, DialerSkipReason $reason): AutoDialerQueueItem
    {
        $session = AutoDialerSession::create(['tenant_id' => 0, 'user_id' => $caller->id]);

        return AutoDialerQueueItem::create([
            'auto_dialer_session_id' => $session->id,
            'lead_id' => $lead->id,
            'position' => 1,
            'state' => 'skipped',
            'skip_reason' => $reason->value,
            'completed_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // The skip-log screen
    // -----------------------------------------------------------------------

    #[Test]
    public function the_suppressed_attempts_screen_renders_for_a_manager(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dnc/skips')
            ->assertOk()
            ->assertSee('Why we did not reach them')
            ->assertSee('On the do-not-contact list now');
    }

    #[Test]
    public function a_role_without_dnc_view_cannot_open_the_suppressed_attempts_screen(): void
    {
        // Accounts is read-only on leads and holds no DNC permission at all.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dnc/skips')
            ->assertStatus(403);
    }

    #[Test]
    public function the_suppressed_attempts_nav_link_is_shown_to_a_manager(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dnc/skips')
            ->assertOk()
            ->assertSee('/dnc/skips"', false);
    }

    #[Test]
    public function the_suppressed_attempts_nav_link_is_hidden_from_a_role_without_dnc_view(): void
    {
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/dnc/skips"', false);
    }

    // -----------------------------------------------------------------------
    // The manual add control on the suppression list
    // -----------------------------------------------------------------------

    #[Test]
    public function the_add_to_dnc_control_is_drawn_for_a_holder_of_dnc_create(): void
    {
        // A telecaller may add a suppression but never lift one (BR-DNC-06).
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dnc')
            ->assertOk()
            ->assertSee('Add to DNC')
            ->assertSee('id="add-modal"', false);
    }

    #[Test]
    public function the_add_to_dnc_control_is_hidden_from_a_user_who_may_only_read_the_list(): void
    {
        $this->actingAs($this->readOnlyDncUser())
            ->get('/dnc')
            ->assertOk()
            ->assertDontSee('Add to DNC')
            ->assertDontSee('id="add-modal"', false);
    }

    // -----------------------------------------------------------------------
    // The endpoint the skip-log screen reads
    // -----------------------------------------------------------------------

    #[Test]
    public function the_skip_log_lists_refused_sends_and_the_calls_the_dialer_never_placed(): void
    {
        $manager = $this->user(RoleName::Manager);
        $lead = Lead::factory()->create(['assigned_to' => null]);

        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();
        $this->skippedMessage($lead);
        $this->skippedCall($lead, $manager, DialerSkipReason::Suppressed);

        // A send that went out is not a refusal and must not appear.
        Message::factory()->for($lead)->sent()->create();

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dnc/skips/log')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.meta.period.from', '2026-08-01');

        $sources = collect($response->json('data.items'))->keyBy('source');

        $this->assertSame('SMS', $sources['manual']['channel_label']);
        $this->assertSame('Auto-dialer', $sources['dialer']['source_label']);

        // Why the attempt was refused, and why the lead is on the list, are two
        // different answers and the screen shows both.
        $this->assertSame('On the do-not-contact list', $sources['dialer']['reason_label']);
        $this->assertSame('Do Not Contact', $sources['manual']['dnc_reason_label']);
        $this->assertTrue($sources['manual']['suppressed']);
    }

    #[Test]
    public function a_skip_the_dnc_list_did_not_cause_is_not_reported_as_a_suppression(): void
    {
        $manager = $this->user(RoleName::Manager);
        $lead = Lead::factory()->create(['assigned_to' => null]);

        $this->skippedCall($lead, $manager, DialerSkipReason::Cooldown);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dnc/skips/log')
            ->assertOk()
            ->assertJsonPath('data.items.0.suppressed', false)
            ->assertJsonPath('data.items.0.reason_label', 'Contacted too recently')
            ->assertJsonPath('data.items.0.dnc_reason', null);
    }

    #[Test]
    public function the_post_queue_window_keeps_its_own_reason(): void
    {
        $manager = $this->user(RoleName::Manager);
        $lead = Lead::factory()->create(['assigned_to' => null]);

        // BR-DNC-03: opted out while the message sat in the queue. Collapsing it
        // into a plain "suppressed" would hide the window it exists to expose.
        $this->skippedMessage($lead, 'suppressed_after_queueing');

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dnc/skips/log')
            ->assertOk()
            ->assertJsonPath('data.items.0.reason_label', 'Opted out while the message was queued')
            ->assertJsonPath('data.items.0.suppressed', true);
    }

    #[Test]
    public function the_skip_log_names_only_leads_the_caller_may_see(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        $mine = Lead::factory()->create(['assigned_to' => $telecaller->id, 'name' => 'Mine Sharma']);
        $theirs = Lead::factory()->create(['assigned_to' => $colleague->id, 'name' => 'Theirs Sharma']);

        $this->skippedMessage($mine);
        $this->skippedMessage($theirs);
        $this->skippedCall($theirs, $colleague, DialerSkipReason::Suppressed);

        // SEC-AUTHZ-03: the aggregate report is unscoped because counts identify
        // nobody; this log names people, so it is scoped like the DNC list.
        $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/dnc/skips/log')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.lead.name', 'Mine Sharma');
    }

    #[Test]
    public function the_skip_log_filters_on_why_the_lead_is_on_the_list(): void
    {
        $manager = $this->user(RoleName::Manager);

        $refused = Lead::factory()->create(['assigned_to' => null, 'name' => 'Refused Rao']);
        $bounced = Lead::factory()->create(['assigned_to' => null, 'name' => 'Bounced Bose']);

        DncEntry::factory()->for($refused)->reason(DncReason::DoNotContact)->create();
        DncEntry::factory()->for($bounced)->reason(DncReason::BouncedEmail)->create();

        $this->skippedMessage($refused);
        $this->skippedMessage($bounced);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dnc/skips/log?filter[dnc_reason]=do_not_contact')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.lead.name', 'Refused Rao');
    }

    #[Test]
    public function an_attempt_outside_the_window_is_not_listed(): void
    {
        $manager = $this->user(RoleName::Manager);
        $lead = Lead::factory()->create(['assigned_to' => null]);

        Message::factory()->for($lead)->skipped()->create([
            'channel' => Channel::Sms->value,
            'created_at' => Carbon::parse('2026-05-04 09:00:00'),
        ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dnc/skips/log')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        // ...and is found again when the window is widened to cover it.
        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dnc/skips/log?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function an_unsupported_filter_on_the_skip_log_is_rejected(): void
    {
        // Ignoring it would show the caller MORE than they asked for, which is
        // the whole reason the list conventions fail loudly (QueryOptions).
        $this->actingAs($this->user(RoleName::Manager), 'sanctum')
            ->getJson('/api/v1/dnc/skips/log?filter[lead_id]=1')
            ->assertStatus(422);
    }

    #[Test]
    public function a_role_without_dnc_view_cannot_read_the_skip_log(): void
    {
        $this->actingAs($this->user(RoleName::Accounts), 'sanctum')
            ->getJson('/api/v1/dnc/skips/log')
            ->assertStatus(403);
    }
}
