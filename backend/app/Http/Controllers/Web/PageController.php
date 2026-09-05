<?php

namespace App\Http\Controllers\Web;

use App\Enums\CallStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\DataScope;
use App\Enums\DncReason;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Page shells for the Web CRM (ADR-A, Phase 8).
 *
 * Deliberately thin. Blade renders the shell and the reference data a page
 * needs to draw its controls; the rows themselves are fetched by the page's
 * jQuery from `/api/v1/*`, same-origin and session-authenticated.
 *
 * The point of that split is that there is exactly ONE implementation of every
 * business rule, and the browser reaches it through the same API the Flutter
 * app will use. A Blade page that queried leads directly would be a second
 * code path for scoping and filtering - and the second path is always the one
 * that gets a rule wrong.
 */
class PageController extends Controller
{
    public function leads(): View
    {
        return view('leads.index', [
            'statuses' => LeadStatus::cases(),
        ]);
    }

    /**
     * Lead detail.
     *
     * Resolves the lead server-side purely so an unauthorised URL is a 403 on
     * page load rather than a working page frame that fills with an error.
     *
     * Resolved with `withTrashed()` instead of by implicit binding, because an
     * ARCHIVED lead is exactly where the Restore control has to live: 404-ing
     * the page would leave `POST /leads/{id}/restore` with no way to reach it
     * from the browser. The page renders read-only in that state and offers
     * Restore; the API still refuses every write against an archived lead.
     */
    public function lead(Request $request, string $lead): View
    {
        $model = Lead::withTrashed()->findOrFail($lead);

        $this->authorize('view', $model);

        return view('leads.show', [
            'lead' => $model,
            'statuses' => LeadStatus::cases(),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Lead create form (FR-LEAD-01, T-46).
     *
     * Until this existed, a lead could only arrive by CSV import or a direct API
     * call - so a telecaller who was handed a number on a phone call had nowhere
     * to put it.
     */
    public function leadCreate(Request $request): View
    {
        $this->authorize('create', Lead::class);

        return view('leads.form', array_merge($this->leadFormData($request), [
            'lead' => null,
        ]));
    }

    /**
     * Lead edit form.
     *
     * Same view as create. The API is what differs - PATCH accepts only the
     * identity and location fields, because status, temperature, score,
     * suppression and ownership each move through their own endpoint
     * (SEC-IN-06). The form therefore hides the create-only sections rather
     * than offering fields the update endpoint would reject.
     */
    public function leadEdit(Request $request, Lead $lead): View
    {
        $this->authorize('update', $lead);

        return view('leads.form', array_merge($this->leadFormData($request), [
            'lead' => $lead->load(['source', 'assignedUser']),
        ]));
    }

    /**
     * Reference data the lead form needs to draw its controls.
     *
     * `viewableAfterCreate` is the one piece of state the page cannot work out
     * for itself: a telecaller is scoped to their own leads, and a manually
     * created lead is unassigned, so the creator would be redirected straight
     * into a 403 on their own new record. The page uses this to send them back
     * to the list with an explanation instead (T-47).
     *
     * @return array<string, mixed>
     */
    private function leadFormData(Request $request): array
    {
        return [
            'sources' => LeadSource::query()->active()->orderBy('name')->get(),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(),
            'tags' => Tag::query()->userEditable()->orderBy('name')->get(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'defaultTimezone' => config('crm.timezone'),
            'viewableAfterCreate' => $request->user()->dataScope() !== DataScope::Own,
        ];
    }

    /**
     * The unassigned pool (FR-LEAD-08/10, BR-ASSIGN-01..05).
     *
     * Leads arrive with no owner from manual creation and from any import or
     * webhook where auto-assignment found nobody eligible. Until this screen
     * existed they simply accumulated: the API could assign them, but nothing
     * listed them, so nobody knew they were there (T-47).
     */
    public function assignments(): View
    {
        $this->authorize('assignAny', Lead::class);

        return view('assignments.index', [
            'statuses' => LeadStatus::cases(),
        ]);
    }

    /**
     * The auto-dialer screen.
     *
     * Everything the page needs to draw its controls comes from here; the run
     * itself is driven entirely through `/api/v1/dialer/*`, so the browser and
     * a future Flutter dialer share one implementation of the skip rules and
     * the single-assignment guarantee.
     */
    public function dialer(): View
    {
        return view('dialer.index', [
            'statuses' => LeadStatus::cases(),
            'callStatuses' => CallStatus::cases(),
        ]);
    }

    /**
     * The signed-in user's own account (SEC-AUTH-04, T-46).
     *
     * Deliberately outside the permission system. Every role needs to be able
     * to change its own password, so gating this would lock the lowest-
     * privileged users out of the one security action they must always have.
     */
    public function account(Request $request): View
    {
        return view('account.index', [
            'user' => $request->user()->load(['roles', 'team']),
        ]);
    }

    /**
     * Business report dashboards (T-60, FR-RPT-03).
     *
     * A shell like every other page: the figures come from `/api/v1/reports/*`,
     * so there is one implementation of each formula and the browser cannot
     * drift from what the API says (ADR-A). The page's only job is to draw
     * them - including drawing every rate with its denominator (FR-RPT-06).
     */
    public function reports(): View
    {
        return view('reports.index');
    }

    /**
     * Telecaller performance dashboard (Phase 26, FR-RPT-01).
     *
     * The people view, gated separately from the money view: `reports.business`
     * opens `/reports`, `reports.telecaller` opens this one, because who-gets-
     * paid and how-the-business-is-doing are different questions asked by
     * different roles. A shell like every other page - the figures come from
     * `/api/v1/reports/telecallers`, so the leaderboard here cannot disagree
     * with the API, and every rate is still drawn with its denominator
     * (FR-RPT-06). The page also names the attribution model the API used,
     * because on reassignment it changes who gets the credit (T-24).
     */
    public function telecallerReports(): View
    {
        return view('reports.telecallers');
    }

    /**
     * Payments (Phase 23, FR-PAY-01/03/04, ROLE-05).
     *
     * The Accounts role's screen, and until this existed it had none: that role
     * holds `payments.view/manage/refund` and nothing in the browser used any
     * of them, so somebody whose whole job is money signed in to a dashboard,
     * a lead list and no way to do it.
     *
     * `canManage` and `canRefund` are passed separately because they are
     * different powers - a refund moves money back out and is deliberately its
     * own permission (SEC-AUTHZ-06).
     */
    public function payments(Request $request): View
    {
        return view('payments.index', [
            'canManage' => $request->user()->hasPermission(Permission::PaymentsManage),
            'canRefund' => $request->user()->hasPermission(Permission::PaymentsRefund),
        ]);
    }

    /**
     * User administration (T-51, closes T-46).
     *
     * `canManageRoles` is passed because the screen needs to know whether to
     * draw the role control at all: `users.manage` and `roles.manage` are
     * different powers held by different roles (SEC-AUTHZ-05), so an Admin sees
     * the page without the one control that would let them promote somebody.
     */
    public function users(Request $request): View
    {
        return view('users.index', [
            'roles' => RoleName::cases(),
            'canManage' => $request->user()->hasPermission(Permission::UsersManage),
            'canManageRoles' => $request->user()->hasPermission(Permission::RolesManage),
        ]);
    }

    /**
     * Suppression administration (BR-DNC-06).
     *
     * The reason list is rendered server-side because the matrix is the rule
     * itself - a hardcoded list in JavaScript would be a second copy of
     * BR-DNC-02 that nothing keeps in step with the enum.
     */
    public function dnc(): View
    {
        return view('dnc.index', [
            'reasons' => DncReason::cases(),
        ]);
    }

    /**
     * The compliance trail (SEC-AUD-02, SEC-AUD-04).
     *
     * Read-only, like everything behind `audit.view` - `audit_logs` is
     * append-only, so this screen has no controls to draw for a second
     * permission the way the payments and users screens do.
     *
     * Two pieces of reference data, both because the alternative is worse than
     * a query. The actor list turns a filter on `user_id` into a name picker
     * rather than asking a reader to know a numeric id. The subject list maps
     * the fully-qualified class names stored in `auditable_type` to labels,
     * because a filter card offering `App\Models\DncEntry` is a filter card
     * nobody uses.
     */
    public function audit(): View
    {
        return view('audit.index', [
            'actors' => User::query()->select(['id', 'name'])->orderBy('name')->get(),
            'subjectTypes' => [
                'Lead' => Lead::class,
                'Suppression' => DncEntry::class,
                'Payment' => Payment::class,
            ],
        ]);
    }

    /**
     * Settings and provider credentials.
     *
     * A pure shell even by this application's standards - the field list, its
     * types and the caller's visibility all come from `/api/v1/settings`, so
     * the registry is the single definition of what is settable and the page
     * cannot drift from it.
     */
    public function settings(): View
    {
        return view('settings.index');
    }

    public function products(): View
    {
        return view('products.index');
    }

    public function imports(): View
    {
        return view('imports.index');
    }

    /**
     * Campaigns (Phase 18).
     *
     * The enums are passed in rather than hardcoded in the Blade, so the
     * filters and the channel list cannot drift from what the API accepts -
     * a hardcoded list here would be a second copy of the lifecycle.
     */
    /**
     * Duplicate review (T-64, BR-DUP-03/04).
     *
     * A pure shell: both sides of each pair arrive from
     * `/api/v1/lead-duplicates`, so the merge rules have one implementation.
     */
    public function leadDuplicates(): View
    {
        return view('leads.duplicates');
    }

    public function campaigns(): View
    {
        return view('campaigns.index', [
            'statuses' => CampaignStatus::cases(),
            'channels' => app(CampaignService::class)->supportedChannels(),
        ]);
    }

    public function campaignCreate(): View
    {
        return view('campaigns.create', [
            'channels' => app(CampaignService::class)->supportedChannels(),
            'leadStatuses' => LeadStatus::cases(),
            'temperatures' => LeadTemperature::cases(),
        ]);
    }

    public function campaign(Campaign $campaign): View
    {
        return view('campaigns.show', [
            'campaign' => $campaign,
            'skipReasons' => CampaignSkipReason::cases(),
        ]);
    }

    /**
     * Your own notification list (FR-NOTIF-03).
     *
     * No reference data: the rows carry their own type labels, and the unread
     * count the bell shows comes from the same API the list reads.
     */
    public function notifications(): View
    {
        return view('notifications.index');
    }

    /**
     * Message template authoring (FR-COMM-02).
     *
     * The channel list comes from the Channel enum so the form cannot offer a
     * channel the API would reject.
     */
    public function templates(Request $request): View
    {
        return view('templates.index', [
            'channels' => Channel::cases(),
            'canManage' => $request->user()->hasPermission(Permission::TemplatesManage),
        ]);
    }

    /**
     * The cross-lead follow-up queue (FR-FUP-04).
     *
     * Distinct from the per-lead tab: this is "who am I due to call today",
     * which is the question a telecaller actually opens the CRM to answer.
     */
    public function followUps(Request $request): View
    {
        return view('follow-ups.index', [
            'statuses' => FollowUpStatus::cases(),
            'canManage' => $request->user()->hasPermission(Permission::FollowUpsManage),
        ]);
    }

    /**
     * Why contact attempts were suppressed (BR-DNC-05).
     *
     * The campaign screen already breaks down campaign skips; this is the DNC
     * log itself, which also covers manual and dialer attempts.
     */
    public function dncSkips(): View
    {
        return view('dnc.skips', [
            'reasons' => DncReason::cases(),
        ]);
    }

    /**
     * Call history across every lead the caller may see (FR-CALL-05).
     */
    public function calls(): View
    {
        return view('calls.index', [
            'callStatuses' => CallStatus::cases(),
        ]);
    }

    /**
     * Sent-message history across every lead the caller may see (FR-COMM-05).
     */
    public function messages(): View
    {
        return view('messages.index', [
            'channels' => Channel::cases(),
        ]);
    }

    /**
     * Tag vocabulary management.
     *
     * System tags are shown but cannot be edited - Tag::userEditable() is the
     * authority on which ones a person may change.
     */
    public function tags(): View
    {
        return view('tags.index');
    }

    /**
     * The interested/hot/warm/product-wise views (Phase 20, Phase 8's one
     * remaining gap).
     *
     * GET /interested-leads has existed since Phase 20 with no screen -
     * temperature and product are passed in so the filters cannot offer a
     * value the API would reject.
     */
    public function interestedLeads(): View
    {
        return view('leads.interested', [
            'temperatures' => LeadTemperature::cases(),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
