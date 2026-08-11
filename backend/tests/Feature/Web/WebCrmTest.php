<?php

namespace Tests\Feature\Web;

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Web CRM pages (Phase 8, ADR-A).
 *
 * These pages are shells: they render navigation and the reference data a page
 * needs, and the rows arrive by AJAX from `/api/v1/*`. So what is worth testing
 * here is not markup - it is that the shell cannot be reached by someone who
 * should not see it, and that session auth works end to end alongside the
 * token auth the API already had.
 */
class WebCrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    // -----------------------------------------------------------------------
    // Sign in
    // -----------------------------------------------------------------------

    #[Test]
    public function the_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in', false);
    }

    #[Test]
    public function a_user_can_sign_in_with_a_session(): void
    {
        $user = $this->user(RoleName::Telecaller, ['password' => bcrypt('correct-horse')]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function signing_in_opens_a_work_session_and_writes_an_audit_entry(): void
    {
        $user = $this->user(RoleName::Telecaller, ['password' => bcrypt('correct-horse')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertRedirect('/dashboard');

        // FR-ATT-01. The browser path must not skip attendance tracking, or
        // telecaller reporting quietly under-counts everyone who uses the web
        // app rather than the phone.
        $this->assertDatabaseHas('user_work_sessions', [
            'user_id' => $user->id,
            'source' => 'web',
            'ended_at' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'login']);
    }

    #[Test]
    public function bad_credentials_do_not_reveal_whether_the_account_exists(): void
    {
        $user = $this->user(RoleName::Telecaller, ['password' => bcrypt('correct-horse')]);

        $wrongPassword = $this->post('/login', ['email' => $user->email, 'password' => 'nope']);
        $noSuchUser = $this->post('/login', ['email' => 'ghost@example.com', 'password' => 'nope']);

        // SEC-AUTH-02: identical messages, or the form becomes an enumeration
        // oracle for anyone with a list of email addresses.
        $this->assertSame(
            $wrongPassword->getSession()->get('errors')->first(),
            $noSuchUser->getSession()->get('errors')->first(),
        );

        $this->assertGuest();
    }

    #[Test]
    public function a_disabled_account_cannot_sign_in(): void
    {
        $user = $this->user(RoleName::Telecaller, [
            'password' => bcrypt('correct-horse'),
            'is_active' => false,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'login_blocked']);
    }

    #[Test]
    public function signing_out_closes_the_work_session(): void
    {
        $user = $this->user(RoleName::Telecaller, ['password' => bcrypt('correct-horse')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);
        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseMissing('user_work_sessions', [
            'user_id' => $user->id,
            'ended_at' => null,
        ]);
    }

    #[Test]
    public function an_authenticated_user_is_sent_past_the_login_page(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller));

        $this->get('/login')->assertRedirect('/dashboard');
        $this->get('/')->assertRedirect('/dashboard');
    }

    // -----------------------------------------------------------------------
    // Page access
    // -----------------------------------------------------------------------

    #[Test]
    public function every_page_requires_a_session(): void
    {
        foreach (['/dashboard', '/leads', '/leads/create', '/assignments', '/account', '/dnc', '/settings', '/users', '/reports', '/products', '/imports'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    #[Test]
    public function the_dashboard_counts_only_what_the_viewer_may_see(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        Lead::factory()->count(3)->create(['assigned_to' => $telecaller->id]);
        Lead::factory()->count(5)->create();     // Somebody else's book.

        $this->actingAs($telecaller)
            ->get('/dashboard')
            ->assertOk()
            // SEC-AUTHZ-03: a dashboard that counted outside the caller's scope
            // would leak the size of the database to a telecaller.
            ->assertSee('>3<', false)
            ->assertDontSee('>8<', false);
    }

    #[Test]
    public function an_admin_sees_every_lead_in_the_counts(): void
    {
        Lead::factory()->count(8)->create();

        $this->actingAs($this->user(RoleName::Admin))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('>8<', false);
    }

    #[Test]
    public function a_role_without_the_permission_cannot_open_the_page(): void
    {
        // Telecallers hold neither leads.import nor products.manage.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/imports')
            ->assertStatus(403);
    }

    #[Test]
    public function a_telecaller_can_open_the_dialer_screen(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dialer')
            ->assertOk()
            ->assertSee('Start a dialling run')
            // FR-CALL-07 made visible: skips are shown, not swallowed.
            ->assertSee('Nothing is skipped silently');
    }

    #[Test]
    public function a_role_without_dialer_use_cannot_open_the_dialer(): void
    {
        // Accounts is read-only on leads and holds no dialer permission.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dialer')
            ->assertStatus(403);
    }

    #[Test]
    public function a_manager_can_open_the_imports_page(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/imports')
            ->assertOk()
            ->assertSee('Upload a file');
    }

    #[Test]
    public function navigation_hides_what_the_user_cannot_use(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            // Path only, not the full URL: the host comes from APP_URL and is
            // not what this test is about.
            ->assertSee('/leads"', false)
            // Hidden for usability. The route middleware is what actually
            // refuses it — a hidden link is still a reachable URL.
            ->assertDontSee('/imports"', false);
    }

    #[Test]
    public function the_lead_page_refuses_a_lead_outside_the_callers_scope(): void
    {
        $colleague = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $colleague->id]);

        // The IDOR check runs on page load, so an unauthorised URL is a clean
        // 403 rather than a working page frame that fills with an error.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get("/leads/{$lead->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function the_lead_page_renders_for_its_owner(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create([
            'assigned_to' => $telecaller->id,
            'name' => 'Ramesh Kumar',
            'status' => LeadStatus::Interested->value,
        ]);

        $this->actingAs($telecaller)
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('Ramesh Kumar')
            ->assertSee('Interested');
    }

    #[Test]
    public function a_suppressed_lead_is_flagged_on_its_page(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->suppressed()->create(['assigned_to' => $telecaller->id]);

        $this->actingAs($telecaller)
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('Do not contact');
    }

    // -----------------------------------------------------------------------
    // Lead create / edit form (T-46)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_create_form_opens_for_a_role_that_may_create_leads(): void
    {
        // Also the regression guard for route ordering: registered after
        // /leads/{lead}, "create" would be bound as a lead id and 404.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/leads/create')
            ->assertOk()
            ->assertSee('Create lead');
    }

    #[Test]
    public function a_read_only_role_cannot_open_the_create_form(): void
    {
        // Accounts holds leads.view but not leads.create.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/leads/create')
            ->assertStatus(403);
    }

    #[Test]
    public function the_leads_list_offers_the_create_button_only_to_those_who_may_create(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/leads')
            ->assertOk()
            ->assertSee('/leads/create"', false);

        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/leads')
            ->assertOk()
            ->assertDontSee('/leads/create"', false);
    }

    #[Test]
    public function the_edit_form_is_prefilled_with_the_leads_current_values(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create([
            'assigned_to' => $telecaller->id,
            'name' => 'Ramesh Kumar',
            'company' => 'Kumar Textiles',
        ]);

        $this->actingAs($telecaller)
            ->get("/leads/{$lead->id}/edit")
            ->assertOk()
            ->assertSee('Save changes')
            ->assertSee('Ramesh Kumar')
            ->assertSee('Kumar Textiles')
            ->assertSee($lead->phone_e164);
    }

    #[Test]
    public function the_edit_form_refuses_a_lead_outside_the_callers_scope(): void
    {
        $colleague = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $colleague->id]);

        // Same IDOR check as the detail page - the edit URL must not be the
        // way around the policy (SEC-AUTHZ-04).
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get("/leads/{$lead->id}/edit")
            ->assertStatus(403);
    }

    #[Test]
    public function a_read_only_role_cannot_open_the_edit_form(): void
    {
        $lead = Lead::factory()->create();

        $this->actingAs($this->user(RoleName::Accounts))
            ->get("/leads/{$lead->id}/edit")
            ->assertStatus(403);
    }

    #[Test]
    public function the_edit_form_does_not_offer_fields_the_update_endpoint_rejects(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id]);

        // Status, products, tags and the opening note are create-only or belong
        // to their own endpoints (SEC-IN-06). A control here would be a control
        // guaranteed to 422.
        $this->actingAs($telecaller)
            ->get("/leads/{$lead->id}/edit")
            ->assertOk()
            ->assertDontSee('id="f-note"', false)
            ->assertDontSee('class="form-check-input f-product"', false);
    }

    #[Test]
    public function the_detail_page_links_to_the_edit_form_only_for_editors(): void
    {
        $lead = Lead::factory()->create();

        $this->actingAs($this->user(RoleName::Manager))
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee("/leads/{$lead->id}/edit", false);

        $this->actingAs($this->user(RoleName::Accounts))
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertDontSee("/leads/{$lead->id}/edit", false);
    }

    // -----------------------------------------------------------------------
    // Assignment UI (T-46, T-47)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_manager_can_open_the_unassigned_pool(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/assignments')
            ->assertOk()
            ->assertSee('Assign selected');
    }

    #[Test]
    public function a_telecaller_cannot_open_the_unassigned_pool(): void
    {
        // Assignment is supervisory - a telecaller holds no leads.assign, so
        // they can neither claim a lead nor push one at a colleague
        // (BR-ASSIGN-05).
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/assignments')
            ->assertStatus(403);
    }

    #[Test]
    public function navigation_shows_assignments_only_to_those_who_may_assign(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/assignments"', false);

        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/assignments"', false);
    }

    #[Test]
    public function the_assignment_card_appears_on_a_lead_only_for_assigners(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id]);

        // Admin rather than Manager: a Manager is Team-scoped and this lead's
        // owner has no team, so the policy would refuse the page for reasons
        // that have nothing to do with the card.
        $this->actingAs($this->user(RoleName::Admin))
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('Return to pool');

        // The owner can work the lead but not re-home it.
        $this->actingAs($telecaller)
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertDontSee('Return to pool');
    }

    // -----------------------------------------------------------------------
    // Account / password change (T-46, SEC-AUTH-04)
    // -----------------------------------------------------------------------

    #[Test]
    public function every_role_can_reach_its_own_account_page(): void
    {
        // Including the least privileged one. Gating this would leave a Viewer
        // unable to respond to a password they believe is compromised.
        foreach (RoleName::cases() as $role) {
            $this->actingAs($this->user($role))
                ->get('/account')
                ->assertOk()
                ->assertSee('Change password');
        }
    }

    #[Test]
    public function the_account_page_shows_who_you_are_without_offering_to_change_it(): void
    {
        $user = $this->user(RoleName::Telecaller, ['name' => 'Priya Sharma']);

        // Name, email, role and team are administrative. A self-service role
        // field would be privilege escalation with extra steps.
        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Priya Sharma')
            ->assertSee($user->email)
            ->assertSee('Ask an administrator');
    }

    #[Test]
    public function the_header_links_to_the_account_page(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/account"', false);
    }

    // -----------------------------------------------------------------------
    // Do Not Contact (T-46, BR-DNC-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_can_view_the_dnc_list_but_is_offered_no_way_to_lift_one(): void
    {
        // Telecallers hold dnc.view and dnc.create but not dnc.remove, so the
        // page renders without the remove control. The API refuses it too -
        // this only asserts the UI does not offer a button that would 403.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dnc')
            ->assertOk()
            ->assertSee('Do Not Contact')
            ->assertSee('const canRemove = false', false);
    }

    #[Test]
    public function a_manager_gets_the_removal_control(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dnc')
            ->assertOk()
            ->assertSee('const canRemove = true', false)
            ->assertSee('Remove suppression');
    }

    #[Test]
    public function a_role_without_dnc_view_cannot_open_the_page(): void
    {
        // Accounts is read-only on leads and holds no DNC permission at all.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dnc')
            ->assertStatus(403);
    }

    #[Test]
    public function navigation_shows_dnc_only_to_those_who_may_see_it(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/dnc"', false);

        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/dnc"', false);
    }

    // -----------------------------------------------------------------------
    // Settings (SEC-CFG-04, SEC-AUTHZ-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function an_admin_can_open_the_settings_page(): void
    {
        $this->actingAs($this->user(RoleName::Admin))
            ->get('/settings')
            ->assertOk()
            ->assertSee('Save changes');
    }

    #[Test]
    public function a_manager_cannot_open_the_settings_page(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/settings')
            ->assertStatus(403);
    }

    #[Test]
    public function the_settings_page_never_renders_a_credential_into_the_markup(): void
    {
        // The page is a shell - the field list arrives by AJAX and secret
        // values are not in that payload either. Asserting on the HTML guards
        // against somebody "helpfully" server-rendering the values later.
        $this->actingAs($this->user(RoleName::SuperAdmin))
            ->get('/settings')
            ->assertOk()
            ->assertDontSee('providers.payment.key_secret')
            ->assertDontSee('MAILERCLOUD');
    }

    #[Test]
    public function navigation_shows_settings_only_to_those_who_may_manage_them(): void
    {
        $this->actingAs($this->user(RoleName::Admin))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/settings"', false);

        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/settings"', false);
    }

    // -----------------------------------------------------------------------
    // User administration (T-51, SEC-AUTHZ-05)
    // -----------------------------------------------------------------------

    #[Test]
    public function an_admin_sees_the_user_page_without_the_role_control(): void
    {
        // The split the security requirement asks for, made visible: an Admin
        // can onboard people but not promote them, so the page is fully usable
        // except for the one control that would let them.
        $this->actingAs($this->user(RoleName::Admin))
            ->get('/users')
            ->assertOk()
            ->assertSee('Add a user')
            ->assertSee('const canManageRoles = false', false)
            ->assertSee('a Super Admin assigns one');
    }

    #[Test]
    public function a_super_admin_gets_the_role_control(): void
    {
        $this->actingAs($this->user(RoleName::SuperAdmin))
            ->get('/users')
            ->assertOk()
            ->assertSee('const canManageRoles = true', false)
            ->assertSee('Save roles');
    }

    #[Test]
    public function a_manager_can_view_users_but_gets_no_create_form(): void
    {
        // Manager holds users.view - useful for assignment screens - but not
        // users.manage.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/users')
            ->assertOk()
            ->assertDontSee('Add a user');
    }

    #[Test]
    public function a_telecaller_cannot_open_the_user_page(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/users')
            ->assertStatus(403);
    }

    #[Test]
    public function navigation_shows_users_only_to_those_who_may_see_them(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/users"', false);

        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/users"', false);
    }

    // -----------------------------------------------------------------------
    // Report dashboards (T-60, FR-RPT-03/06)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_manager_can_open_the_reports_page(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/reports')
            ->assertOk()
            // FR-RPT-03 names Chart.js specifically.
            ->assertSee('chart.js', false)
            ->assertSee('Lead funnel');
    }

    #[Test]
    public function the_reports_page_warns_against_summing_booked_and_collected(): void
    {
        // The classic mistake, and the chart puts the two bars side by side -
        // so the page says it out loud (GLOSSARY 2.5).
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/reports')
            ->assertOk()
            ->assertSee('never added together');
    }

    #[Test]
    public function a_telecaller_cannot_open_the_reports_page(): void
    {
        // Telecaller holds reports.view but not reports.business - the business
        // dashboard is the organisation-wide view.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/reports')
            ->assertStatus(403);
    }

    #[Test]
    public function navigation_shows_reports_only_to_those_who_may_see_them(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/reports"', false);

        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/reports"', false);
    }

    // -----------------------------------------------------------------------
    // Lead detail tabs for Phases 13/15, 21, 22/23
    // -----------------------------------------------------------------------

    #[Test]
    public function the_lead_page_surfaces_follow_ups_messages_and_deals(): void
    {
        $lead = Lead::factory()->create();

        // Those phases shipped their APIs with no UI, which makes them
        // unreachable to the people they were built for - the same gap the
        // dialer and DNC screens closed earlier.
        $this->actingAs($this->user(RoleName::Admin))
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('#tab-followups', false)
            ->assertSee('#tab-messages', false)
            ->assertSee('#tab-deals', false);
    }

    #[Test]
    public function the_follow_ups_tab_is_hidden_from_roles_without_the_permission(): void
    {
        $lead = Lead::factory()->create();

        // Accounts holds no follow_ups permission. It does hold sales.view -
        // as every seeded role does - so the Deals tab is correctly present
        // for it; only its write controls are absent.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertDontSee('#tab-followups', false)
            ->assertSee('#tab-deals', false);
    }

    #[Test]
    public function a_read_only_role_gets_no_write_controls_on_the_new_tabs(): void
    {
        $lead = Lead::factory()->create();

        // Accounts is read-only on leads: sales.view without sales.manage, and
        // no messages.send. It can read a deal but not open one or send.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertDontSee('id="opp-title"', false)
            ->assertDontSee('id="msg-send"', false);
    }

    #[Test]
    public function a_telecaller_can_work_follow_ups_and_send_from_the_lead_page(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id]);

        // The people the tabs were built for: follow_ups.manage and
        // messages.send, but not sales.manage.
        $this->actingAs($telecaller)
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('id="fu-create"', false)
            ->assertSee('id="msg-send"', false)
            ->assertDontSee('id="opp-title"', false);
    }

    // -----------------------------------------------------------------------
    // Dashboard "your work" panel
    // -----------------------------------------------------------------------

    #[Test]
    public function the_dashboard_shows_a_telecaller_their_own_follow_ups(): void
    {
        $me = $this->user(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        FollowUp::factory()->count(2)->for(Lead::factory())->create([
            'assigned_to' => $me->id,
            'scheduled_at' => now(),
            'status' => 'open',
        ]);
        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $me->id,
            'status' => 'missed',
        ]);
        // Somebody else's work must not appear in my count.
        FollowUp::factory()->count(5)->for(Lead::factory())->create([
            'assigned_to' => $colleague->id,
            'scheduled_at' => now(),
            'status' => 'open',
        ]);

        $this->actingAs($me)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Follow-ups due')
            ->assertSee('>2<', false)
            ->assertDontSee('>5<', false);
    }

    #[Test]
    public function a_future_follow_up_is_not_todays_work(): void
    {
        $me = $this->user(RoleName::Telecaller);

        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $me->id,
            'scheduled_at' => now()->addWeek(),
            'status' => 'open',
        ]);

        // A to-do list including next month's reminders is not a to-do list.
        $this->actingAs($me)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Follow-ups due')
            ->assertSee('>0<', false);
    }

    #[Test]
    public function the_panel_only_shows_blocks_the_role_may_see(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => null]);

        // A telecaller cannot assign and holds no payments permission, so
        // neither block is drawn for them.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Awaiting an owner')
            ->assertDontSee('Payments overdue');

        // Accounts holds payments.view but cannot assign.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Payments overdue')
            ->assertDontSee('Awaiting an owner');

        $this->actingAs($this->user(RoleName::Admin))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Awaiting an owner')
            ->assertSee('Payments overdue');
    }

    #[Test]
    public function a_role_without_follow_up_permission_gets_no_follow_up_counts(): void
    {
        // Accounts holds no follow_ups permission - the block is omitted
        // rather than shown as zero, which would imply they have none.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Follow-ups due');
    }

    // -----------------------------------------------------------------------
    // Session auth against the API
    // -----------------------------------------------------------------------

    #[Test]
    public function the_api_still_accepts_bearer_tokens(): void
    {
        $user = $this->user(RoleName::Telecaller);
        $token = $user->createToken('test')->plainTextToken;

        // Session auth was added alongside token auth, not instead of it — the
        // Flutter app depends on this path (ARCHITECTURE §1).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/leads')
            ->assertOk();
    }

    #[Test]
    public function an_unauthenticated_api_call_is_still_a_401_not_a_redirect(): void
    {
        // A browser page redirects to /login; the API must not. A redirect
        // here would hand an AJAX caller an HTML login page it cannot parse.
        $this->getJson('/api/v1/leads')->assertStatus(401);
    }
}
