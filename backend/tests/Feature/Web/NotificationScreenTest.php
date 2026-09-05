<?php

namespace Tests\Feature\Web;

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The notifications screen and the shell's bell (FR-NOTIF-01, FR-NOTIF-03).
 *
 * The screen is a shell like every other Web CRM page - rows arrive by AJAX -
 * so the interesting assertions are about reach, not markup. Two things here
 * are genuinely load-bearing and easy to break by accident:
 *
 *  - the screen has NO permission gate, because these are the caller's own
 *    records and the API binds every query to `$request->user()`. A gate added
 *    "for consistency" would lock people out of their own alerts;
 *  - the bell is in the layout, not on a page, and it is the ONLY entry point
 *    to the screen. If it silently drops out of the shell the feature becomes
 *    unreachable without anything failing.
 */
class NotificationScreenTest extends TestCase
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

    // -----------------------------------------------------------------------
    // The screen
    // -----------------------------------------------------------------------

    #[Test]
    public function the_notifications_screen_renders_for_an_authenticated_user(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/notifications')
            ->assertOk()
            ->assertSee('Mark all read')
            ->assertSee('Unread only')
            ->assertSee('All notifications');
    }

    #[Test]
    public function a_guest_is_sent_to_the_sign_in_page(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
    }

    /**
     * The whole point of BR-NOTIF-01: there is no "someone else's
     * notifications" to gate, so no role may be refused its own list. A
     * Viewer - the least privileged role there is - has to get in.
     */
    #[Test]
    public function every_role_can_reach_its_own_notifications(): void
    {
        foreach (RoleName::cases() as $role) {
            $this->actingAs($this->user($role))
                ->get('/notifications')
                ->assertOk();
        }
    }

    /**
     * Notifications outlive permission changes, so the screen only offers a
     * link when the viewer can actually open the target - a link that lands on
     * a 403 reads as a broken screen rather than a closed door. A telecaller
     * has leads.view but not campaigns.view.
     */
    #[Test]
    public function link_targets_are_gated_on_what_the_viewer_may_open(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/notifications')
            ->assertOk()
            ->assertSee('leads: true', false)
            ->assertSee('campaigns: false', false);

        // A viewer holds campaigns.view, so the campaign links are drawn.
        $this->actingAs($this->user(RoleName::Viewer))
            ->get('/notifications')
            ->assertOk()
            ->assertSee('campaigns: true', false);
    }

    // -----------------------------------------------------------------------
    // The bell
    // -----------------------------------------------------------------------

    /**
     * On a page that is not the notifications screen, which is the only way to
     * prove the bell lives in the shell rather than on one view.
     */
    #[Test]
    public function the_bell_is_in_the_shell_on_every_page(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        foreach (['/dashboard', '/leads', '/account'] as $path) {
            $this->actingAs($telecaller)
                ->get($path)
                ->assertOk()
                ->assertSee('id="crm-bell"', false)
                ->assertSee('/notifications"', false)
                ->assertSee('bi-bell', false);
        }
    }

    /**
     * The bell IS the entry point - there is deliberately no sidebar link, so
     * the path must appear exactly once in the shell. A second copy means
     * somebody added a nav entry and the two will drift.
     */
    #[Test]
    public function the_bell_is_the_only_entry_point_to_the_screen(): void
    {
        $content = $this->actingAs($this->user(RoleName::Admin))
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        // Matched on the path with a trailing quote so the assertion does not
        // depend on APP_URL. The unread-count endpoint has a longer path and
        // cannot collide with it.
        $this->assertSame(1, substr_count((string) $content, '/notifications"'));
    }

    /**
     * The count arrives by AJAX, so the badge must ship empty and hidden. If it
     * ever rendered server-side it would flash a stale or zero number, and
     * "0 unread" is a badge asking for attention it does not need.
     */
    #[Test]
    public function the_badge_ships_empty_and_hidden_rather_than_showing_a_zero(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="crm-bell-count"', false)
            ->assertSee('d-none"></span>', false);
    }

    /** The poll interval is the feature; a bell that never refreshes is a link. */
    #[Test]
    public function the_bell_polls_the_unread_count_endpoint(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/api/v1/notifications/unread-count', false)
            ->assertSee('60000', false);
    }

    // -----------------------------------------------------------------------
    // The break toggle (FR-ATT-04) - lives in the same shell, for the same
    // reason: a break is a state of the whole session, reachable from
    // wherever a shift happens to be, not a section of the CRM.
    // -----------------------------------------------------------------------

    /**
     * On a page that is not the notifications screen, same proof as the bell:
     * the control lives in the shell, not on one view.
     */
    #[Test]
    public function the_break_toggle_is_in_the_shell_on_every_page(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        foreach (['/dashboard', '/leads', '/account'] as $path) {
            $this->actingAs($telecaller)
                ->get($path)
                ->assertOk()
                ->assertSee('id="crm-break-toggle"', false)
                ->assertSee('/api/v1/attendance/status', false);
        }
    }

    /**
     * Ships hidden (`d-none`) because a page reload has no way to know yet
     * whether the caller even has an open session - the same reason the
     * unread badge ships empty rather than flashing a stale number.
     */
    #[Test]
    public function the_break_toggle_ships_hidden_until_status_answers(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="crm-break-toggle" class="btn btn-sm btn-outline-secondary d-none"', false);
    }

    #[Test]
    public function the_break_toggle_posts_to_the_break_endpoints(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/api/v1/attendance/breaks/', false);
    }

    // -----------------------------------------------------------------------
    // The contract the screen reads
    // -----------------------------------------------------------------------

    /**
     * The view renders raw model fields - the endpoint has no API resource - so
     * a rename in the notifications table would silently blank a column rather
     * than fail anything. These are the exact keys the screen and the bell read
     * (ADR-A: the page is a shell, so this contract is the only thing holding
     * it together).
     */
    #[Test]
    public function the_list_returns_the_fields_the_screen_renders(): void
    {
        $user = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create();

        app(NotificationService::class)->notify($user, 'lead_assigned', 'New lead: Acme', [
            'body' => 'Assigned to you.',
            'reference' => $lead,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.items.0.type', 'lead_assigned')
            ->assertJsonPath('data.items.0.title', 'New lead: Acme')
            ->assertJsonPath('data.items.0.body', 'Assigned to you.')
            ->assertJsonPath('data.items.0.read_at', null)
            // The fallback link for lead_assigned is built from these two, as
            // LeadAssignmentService writes no action_url.
            ->assertJsonPath('data.items.0.reference_type', Lead::class)
            ->assertJsonPath('data.items.0.reference_id', $lead->id)
            ->assertJsonStructure(['data' => [
                'items' => [['id', 'type', 'title', 'body', 'action_url', 'read_at', 'created_at']],
                'meta' => ['current_page', 'last_page', 'total', 'unread_count'],
            ]])
            // Carried on the list response, which is why the screen's summary
            // and "mark all" button need no second round trip.
            ->assertJsonPath('data.meta.unread_count', 1);
    }

    /**
     * The reference_type the view keys its fallback map on is the fully
     * qualified class name, backslashes and all. The JS map hard-codes
     * 'App\\Models\\Lead'; if the model ever moved, the commonest notification
     * would quietly stop being clickable.
     */
    #[Test]
    public function the_lead_reference_type_matches_the_class_the_view_keys_on(): void
    {
        $this->assertSame('App\Models\Lead', Lead::class);

        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/notifications')
            ->assertOk()
            ->assertSee("'App\\\\Models\\\\Lead'", false);
    }

    /** The unread filter and the mark-read writes the screen's buttons call. */
    #[Test]
    public function the_screen_can_filter_unread_and_mark_them_read(): void
    {
        $user = $this->user(RoleName::Telecaller);
        $notifications = app(NotificationService::class);

        $first = $notifications->notify($user, 'follow_up_due', 'Follow-up due: Acme');
        $notifications->notify($user, 'payment_overdue', 'Payment overdue: Acme');

        $this->actingAs($user)
            ->postJson('/api/v1/notifications/'.$first->id.'/read')
            ->assertOk();

        // The screen sends `unread=1` only when the filter is on - a bare
        // boolean, not a filter[] key like the other list screens use.
        $this->actingAs($user)
            ->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.type', 'payment_overdue')
            ->assertJsonPath('data.meta.unread_count', 1);

        $this->actingAs($user)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk();

        // What the bell repaints from after "mark all read".
        $this->actingAs($user)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    /**
     * SEC-AUTHZ-04. The screen puts a notification id in a URL, so the id is
     * the most trivially enumerable thing on the page - 404, not 403, so the
     * error does not confirm the row exists.
     */
    #[Test]
    public function one_user_cannot_mark_another_users_notification_read(): void
    {
        $owner = $this->user(RoleName::Telecaller);
        $other = $this->user(RoleName::Telecaller);

        $notification = app(NotificationService::class)
            ->notify($owner, 'lead_assigned', 'New lead: Acme');

        $this->actingAs($other)
            ->postJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }
}
