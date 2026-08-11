<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CallController;
use App\Http\Controllers\Api\V1\DialerController;
use App\Http\Controllers\Api\V1\DncController;
use App\Http\Controllers\Api\V1\FollowUpController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InterestController;
use App\Http\Controllers\Api\V1\LeadAssignmentController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadImportController;
use App\Http\Controllers\Api\V1\LeadNoteController;
use App\Http\Controllers\Api\V1\LeadProductController;
use App\Http\Controllers\Api\V1\LeadStatusController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\MetaWebhookController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OpportunityController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QuotationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 (see bootstrap/app.php).
|
| Endpoint groups are added by the phase that implements them - see
| docs/API_DOCUMENTATION.md §11. Routes are never added here ahead of a
| working, tested implementation.
|
| Rate limiters (config/crm.php):
|   throttle:api-auth      5/min   - authentication
|   throttle:api-standard  120/min - normal authenticated traffic
|   throttle:api-bulk      10/min  - campaign starts, imports
|   throttle:api-webhook   600/min - inbound provider webhooks
*/

// Liveness/readiness. Unauthenticated by design - a monitor cannot hold
// credentials - so it exposes no business data.
Route::get('/health', [HealthController::class, 'index'])
    ->middleware('throttle:api-standard')
    ->name('api.v1.health');

/*
|--------------------------------------------------------------------------
| Authentication (Phase 4)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    // Throttled per IP AND per submitted email so rotating IPs cannot
    // brute-force one account (SEC-AUTH-03).
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:api-auth')
        ->name('api.v1.auth.login');

    Route::middleware(['auth:sanctum', 'throttle:api-standard'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('api.v1.auth.logout-all');
        Route::post('/change-password', [AuthController::class, 'changePassword'])
            ->name('api.v1.auth.change-password');
    });
});

/*
|--------------------------------------------------------------------------
| Products (Phase 5)
|--------------------------------------------------------------------------
| Read is available to anyone who can see leads - product names appear all over
| the CRM. Writes require products.manage (Admin+).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index'])
        ->middleware('permission:products.view')->name('api.v1.products.index');

    Route::get('/{product}', [ProductController::class, 'show'])
        ->middleware('permission:products.view')->name('api.v1.products.show');

    Route::post('/', [ProductController::class, 'store'])
        ->middleware('permission:products.manage')->name('api.v1.products.store');

    Route::patch('/{product}', [ProductController::class, 'update'])
        ->middleware('permission:products.manage')->name('api.v1.products.update');

    // Archives, never destroys - product history feeds reporting.
    Route::delete('/{product}', [ProductController::class, 'destroy'])
        ->middleware('permission:products.manage')->name('api.v1.products.destroy');

    Route::post('/{id}/restore', [ProductController::class, 'restore'])
        ->middleware('permission:products.manage')->name('api.v1.products.restore');
});

/*
|--------------------------------------------------------------------------
| Leads (Phase 6)
|--------------------------------------------------------------------------
| Every route carries a permission gate AND a policy check on the record.
| The gate answers "may they touch leads?"; the policy answers "may they touch
| THIS lead?" - without the second, changing an id in the URL reads a
| colleague's lead (SEC-AUTHZ-04).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->prefix('leads')->group(function () {
    Route::get('/', [LeadController::class, 'index'])
        ->middleware('permission:leads.view')->name('api.v1.leads.index');

    Route::post('/', [LeadController::class, 'store'])
        ->middleware('permission:leads.create')->name('api.v1.leads.store');

    // Static segments before {lead} so they are not captured as an id.
    Route::get('/assignees', [LeadAssignmentController::class, 'eligibleAssignees'])
        ->middleware('permission:leads.assign')->name('api.v1.leads.assignees');

    /*
     * Bulk import (FR-LEAD-07).
     *
     * The upload carries the stricter bulk limiter on top of the group's
     * standard one: a 50,000-row file is not a request anyone should be able
     * to fire 120 times a minute. Reads of the resulting report are ordinary
     * traffic and stay on the standard limiter.
     */
    Route::post('/import', [LeadImportController::class, 'store'])
        ->middleware(['permission:leads.import', 'throttle:api-bulk'])
        ->name('api.v1.leads.import');

    Route::get('/imports', [LeadImportController::class, 'index'])
        ->middleware('permission:leads.import')->name('api.v1.leads.imports.index');
    Route::get('/imports/{leadImport}', [LeadImportController::class, 'show'])
        ->middleware('permission:leads.import')->name('api.v1.leads.imports.show');
    Route::get('/imports/{leadImport}/rows', [LeadImportController::class, 'rows'])
        ->middleware('permission:leads.import')->name('api.v1.leads.imports.rows');

    Route::get('/{lead}', [LeadController::class, 'show'])
        ->middleware('permission:leads.view')->name('api.v1.leads.show');

    Route::patch('/{lead}', [LeadController::class, 'update'])
        ->middleware('permission:leads.update')->name('api.v1.leads.update');

    Route::delete('/{lead}', [LeadController::class, 'destroy'])
        ->middleware('permission:leads.archive')->name('api.v1.leads.destroy');

    Route::post('/{id}/restore', [LeadController::class, 'restore'])
        ->middleware('permission:leads.archive')->name('api.v1.leads.restore');

    // Notes and timeline
    Route::get('/{lead}/notes', [LeadNoteController::class, 'index'])
        ->middleware('permission:leads.view')->name('api.v1.leads.notes.index');
    Route::post('/{lead}/notes', [LeadNoteController::class, 'store'])
        ->middleware('permission:leads.update')->name('api.v1.leads.notes.store');
    Route::get('/{lead}/timeline', [LeadNoteController::class, 'timeline'])
        ->middleware('permission:leads.view')->name('api.v1.leads.timeline');

    /*
     * Status (Phase 7).
     *
     * Status is NOT part of PATCH /leads/{id}. It moves only here, because
     * every change must run the transition matrix, check authority, write
     * append-only history and - for Not Interested - write suppression
     * (BR-STAT-02/03/05, BR-DNC-07).
     */
    Route::patch('/{lead}/status', [LeadStatusController::class, 'update'])
        ->middleware('permission:leads.update')->name('api.v1.leads.status.update');
    Route::get('/{lead}/transitions', [LeadStatusController::class, 'transitions'])
        ->middleware('permission:leads.view')->name('api.v1.leads.transitions');
    Route::get('/{lead}/status-history', [LeadStatusController::class, 'history'])
        ->middleware('permission:leads.view')->name('api.v1.leads.status-history');

    /*
     * Per-product interest (Phase 7). Scope-bound: /leads/5/products/9 where
     * interest 9 belongs to another lead is a 404, not a leak.
     */
    Route::get('/{lead}/products', [LeadProductController::class, 'index'])
        ->middleware('permission:leads.view')->name('api.v1.leads.products.index');
    Route::post('/{lead}/products', [LeadProductController::class, 'store'])
        ->middleware('permission:leads.update')->name('api.v1.leads.products.store');
    Route::patch('/{lead}/products/{leadProduct}', [LeadProductController::class, 'update'])
        ->middleware('permission:leads.update')->scopeBindings()->name('api.v1.leads.products.update');
    Route::delete('/{lead}/products/{leadProduct}', [LeadProductController::class, 'destroy'])
        ->middleware('permission:leads.update')->scopeBindings()->name('api.v1.leads.products.destroy');

    /*
     * Calling (Phase 9, ADR-B).
     *
     * The Web CRM decides whether a call is allowed and records it; the device
     * dials. `callability` exists so a UI can grey out the button with a
     * reason rather than letting a telecaller find out by being refused.
     */
    Route::get('/{lead}/callability', [CallController::class, 'callability'])
        ->middleware('permission:calls.view')->name('api.v1.leads.callability');
    Route::get('/{lead}/calls', [CallController::class, 'index'])
        ->middleware('permission:calls.view')->name('api.v1.leads.calls.index');
    Route::post('/{lead}/calls', [CallController::class, 'store'])
        ->middleware('permission:calls.create')->name('api.v1.leads.calls.store');

    // Assignment - supervisory, separate permission
    Route::post('/{lead}/assign', [LeadAssignmentController::class, 'assign'])
        ->middleware('permission:leads.assign')->name('api.v1.leads.assign');
    Route::post('/{lead}/unassign', [LeadAssignmentController::class, 'unassign'])
        ->middleware('permission:leads.assign')->name('api.v1.leads.unassign');
    Route::post('/{lead}/auto-assign', [LeadAssignmentController::class, 'autoAssign'])
        ->middleware('permission:leads.assign')->name('api.v1.leads.auto-assign');
    Route::get('/{lead}/assignments', [LeadAssignmentController::class, 'history'])
        ->middleware('permission:leads.view')->name('api.v1.leads.assignments');
});

/*
|--------------------------------------------------------------------------
| User administration (T-51)
|--------------------------------------------------------------------------
| Two permissions, deliberately separated. `users.manage` creates and edits
| people; `roles.manage` decides what they may do. Admin holds the first, only
| Super Admin holds the second - so an Admin can onboard a telecaller but
| cannot promote one, and nobody can promote themselves (SEC-AUTHZ-05).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index'])
        ->middleware('permission:users.view')->name('api.v1.users.index');
    Route::get('/{user}', [UserController::class, 'show'])
        ->middleware('permission:users.view')->name('api.v1.users.show');

    Route::post('/', [UserController::class, 'store'])
        ->middleware('permission:users.manage')->name('api.v1.users.store');
    Route::patch('/{user}', [UserController::class, 'update'])
        ->middleware('permission:users.manage')->name('api.v1.users.update');

    Route::post('/{user}/disable', [UserController::class, 'disable'])
        ->middleware('permission:users.manage')->name('api.v1.users.disable');
    Route::post('/{user}/enable', [UserController::class, 'enable'])
        ->middleware('permission:users.manage')->name('api.v1.users.enable');

    // Super Admin only. `roles.manage` is in Permission::isAudited().
    Route::put('/{user}/roles', [UserController::class, 'setRoles'])
        ->middleware('permission:roles.manage')->name('api.v1.users.roles');
});

/*
|--------------------------------------------------------------------------
| Business reports (Phase 27)
|--------------------------------------------------------------------------
| Gated on `reports.business` (Manager+) and deliberately NOT data-scoped: a
| business dashboard is the organisation-wide view, and scoping it to the
| caller's own leads would produce a number that looks like a company total and
| is not one.
|
| Telecaller performance (FR-RPT-01) is absent - it needs the attribution model
| decided, and that decision affects pay (T-24, Phase 26).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->prefix('reports')->group(function () {
    Route::get('/summary', [ReportController::class, 'summary'])
        ->middleware('permission:reports.business')->name('api.v1.reports.summary');
    Route::get('/revenue', [ReportController::class, 'revenue'])
        ->middleware('permission:reports.business')->name('api.v1.reports.revenue');
    Route::get('/pipeline', [ReportController::class, 'pipeline'])
        ->middleware('permission:reports.business')->name('api.v1.reports.pipeline');
    Route::get('/products', [ReportController::class, 'products'])
        ->middleware('permission:reports.business')->name('api.v1.reports.products');
    Route::get('/sources', [ReportController::class, 'sources'])
        ->middleware('permission:reports.business')->name('api.v1.reports.sources');
});

/*
|--------------------------------------------------------------------------
| Interest engine (Phase 20)
|--------------------------------------------------------------------------
| The manual CRM action of BR-INT-01's eight sources. The other seven call
| `InterestEngine` from their own modules - one engine, no channel implements
| its own interest logic.
|
| Gated on `leads.update`: recording interest moves status, applies a label and
| can create a follow-up, which is working the lead rather than reading it.
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->group(function () {
    /*
     * `/interested-leads`, not `/leads/interested`: the leads group registers
     * `GET /leads/{lead}` earlier in this file, so the nested form would be
     * matched first and "interested" bound as a lead id. A distinct path is
     * clearer than a rule about registration order that the next person has to
     * rediscover.
     */
    Route::get('/interested-leads', [InterestController::class, 'interested'])
        ->middleware('permission:leads.view')->name('api.v1.leads.interested');

    Route::post('/leads/{lead}/interest', [InterestController::class, 'store'])
        ->middleware('permission:leads.update')->name('api.v1.leads.interest.store');

    // BR-SCORE-01: a telecaller must be able to see WHY a lead is Hot.
    Route::get('/leads/{lead}/score', [InterestController::class, 'explain'])
        ->middleware('permission:leads.view')->name('api.v1.leads.score');
});

/*
|--------------------------------------------------------------------------
| Payments (Phase 23 - offline half; the gateway needs T-34 named)
|--------------------------------------------------------------------------
| Scoped through the lead. `payments.refund` is a separate permission checked
| inside the controller, because one transition endpoint serves every move and
| only one of them sends money back out (SEC-AUTHZ-06).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->group(function () {
    Route::get('/payments', [PaymentController::class, 'index'])
        ->middleware('permission:payments.view')->name('api.v1.payments.index');

    Route::get('/sales/{sale}/payments', [PaymentController::class, 'forSale'])
        ->middleware('permission:payments.view')->name('api.v1.sales.payments.index');
    Route::post('/sales/{sale}/payments', [PaymentController::class, 'store'])
        ->middleware('permission:payments.manage')->name('api.v1.sales.payments.store');

    Route::patch('/payments/{payment}', [PaymentController::class, 'transition'])
        ->middleware('permission:payments.manage')->name('api.v1.payments.transition');
});

/*
|--------------------------------------------------------------------------
| Sales (Phase 22)
|--------------------------------------------------------------------------
| Opportunities are scoped through the lead, like follow-ups and messages, so
| LeadPolicy stays the single implementation of who sees what.
|
| Approval carries `discounts.approve` - Manager and above, already in
| Permission::isAudited() (BR-SALE-03, SEC-AUTHZ-06).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->group(function () {
    Route::get('/opportunities', [OpportunityController::class, 'index'])
        ->middleware('permission:sales.view')->name('api.v1.opportunities.index');

    Route::get('/leads/{lead}/opportunities', [OpportunityController::class, 'forLead'])
        ->middleware('permission:sales.view')->name('api.v1.leads.opportunities.index');
    Route::post('/leads/{lead}/opportunities', [OpportunityController::class, 'store'])
        ->middleware('permission:sales.manage')->name('api.v1.leads.opportunities.store');

    Route::post('/opportunities/{opportunity}/products', [OpportunityController::class, 'addProduct'])
        ->middleware('permission:sales.manage')->name('api.v1.opportunities.products.add');
    Route::delete('/opportunities/{opportunity}/products/{productId}', [OpportunityController::class, 'removeProduct'])
        ->middleware('permission:sales.manage')->name('api.v1.opportunities.products.remove');

    Route::post('/opportunities/{opportunity}/lost', [OpportunityController::class, 'markLost'])
        ->middleware('permission:sales.manage')->name('api.v1.opportunities.lost');

    // Creates the Customer and unblocks `Converted` (BR-CUST-01, BR-STAT-05).
    Route::post('/opportunities/{opportunity}/sale', [OpportunityController::class, 'recordSale'])
        ->middleware('permission:sales.manage')->name('api.v1.opportunities.sale');

    Route::get('/opportunities/{opportunity}/quotations', [QuotationController::class, 'index'])
        ->middleware('permission:sales.view')->name('api.v1.opportunities.quotations.index');
    Route::post('/opportunities/{opportunity}/quotations', [QuotationController::class, 'store'])
        ->middleware('permission:sales.manage')->name('api.v1.opportunities.quotations.store');

    Route::post('/quotations/{quotation}/approve', [QuotationController::class, 'approve'])
        ->middleware('permission:discounts.approve')->name('api.v1.quotations.approve');
    Route::post('/quotations/{quotation}/reject', [QuotationController::class, 'reject'])
        ->middleware('permission:discounts.approve')->name('api.v1.quotations.reject');
    Route::post('/quotations/{quotation}/issue', [QuotationController::class, 'issue'])
        ->middleware('permission:sales.manage')->name('api.v1.quotations.issue');
});

/*
|--------------------------------------------------------------------------
| Follow-ups and notifications (Phase 21)
|--------------------------------------------------------------------------
| Follow-up routes carry `follow_ups.*` permissions AND a policy check on the
| lead - a follow-up is reachable exactly when its lead is, delegated to
| LeadPolicy rather than reimplemented.
|
| Notification routes carry NO permission. They are the caller's own records,
| bound to the authenticated user; there is no "someone else's notifications"
| to gate (BR-NOTIF-01).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->group(function () {
    Route::get('/follow-ups', [FollowUpController::class, 'mine'])
        ->middleware('permission:follow_ups.view')->name('api.v1.follow-ups.mine');

    Route::get('/leads/{lead}/follow-ups', [FollowUpController::class, 'index'])
        ->middleware('permission:follow_ups.view')->name('api.v1.leads.follow-ups.index');
    Route::post('/leads/{lead}/follow-ups', [FollowUpController::class, 'store'])
        ->middleware('permission:follow_ups.manage')->name('api.v1.leads.follow-ups.store');

    Route::post('/follow-ups/{followUp}/complete', [FollowUpController::class, 'complete'])
        ->middleware('permission:follow_ups.manage')->name('api.v1.follow-ups.complete');
    Route::post('/follow-ups/{followUp}/reschedule', [FollowUpController::class, 'reschedule'])
        ->middleware('permission:follow_ups.manage')->name('api.v1.follow-ups.reschedule');
    Route::post('/follow-ups/{followUp}/cancel', [FollowUpController::class, 'cancel'])
        ->middleware('permission:follow_ups.manage')->name('api.v1.follow-ups.cancel');

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('api.v1.notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('api.v1.notifications.unread-count');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('api.v1.notifications.read-all');
    // After the static segments, or "read-all" binds as an id.
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('api.v1.notifications.read');
});

/*
|--------------------------------------------------------------------------
| Messaging (Phase 13 - Email; the other channels are additional drivers)
|--------------------------------------------------------------------------
| Channel-agnostic. Email is the only live provider today; SMS, WhatsApp, RCS
| and Voice arrive behind `MessageDriverManager` and need no route changes.
|
| Reads are gated on `leads.view` rather than a messages permission - message
| history is lead data, and there is no `messages.view` in the model.
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->group(function () {
    Route::get('/messages', [MessageController::class, 'history'])
        ->middleware('permission:leads.view')->name('api.v1.messages.history');

    Route::get('/leads/{lead}/messages', [MessageController::class, 'index'])
        ->middleware('permission:leads.view')->name('api.v1.leads.messages.index');

    Route::post('/leads/{lead}/messages', [MessageController::class, 'store'])
        ->middleware('permission:messages.send')->name('api.v1.leads.messages.store');
});

/*
|--------------------------------------------------------------------------
| Inbound provider webhooks (FR-COMM-03, SEC-WH-*)
|--------------------------------------------------------------------------
| Unauthenticated by necessity - a provider has no session. Protected by a
| shared secret checked in constant time, the higher webhook rate limit, and
| the rule that nothing in a payload may create a record.
*/
Route::middleware('throttle:api-webhook')->prefix('webhooks')->group(function () {
    Route::post('/mailercloud', [WebhookController::class, 'mailercloud'])
        ->name('api.v1.webhooks.mailercloud');

    /*
     * Meta uses one path for both: a GET carrying the subscription challenge,
     * and POSTs carrying leadgen notifications (SEC-WH-02).
     */
    Route::get('/meta', [MetaWebhookController::class, 'verify'])
        ->name('api.v1.webhooks.meta.verify');
    Route::post('/meta', [MetaWebhookController::class, 'receive'])
        ->name('api.v1.webhooks.meta');
});

/*
|--------------------------------------------------------------------------
| Settings and provider credentials
|--------------------------------------------------------------------------
| An override layer over config, not a replacement (SEC-CFG-01). The route
| gate is `settings.manage`; individual credential keys additionally require
| `credentials.manage`, which is Super Admin only and is checked per key
| inside the controller - one save can legitimately touch both classes.
|
| `credentials.manage` is in Permission::isAudited(), and the service writes
| its own `setting_changed` / `credential_changed` entries with the key but
| never the value (SEC-AUD-02, SEC-CFG-05).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->prefix('settings')->group(function () {
    Route::get('/', [SettingsController::class, 'index'])
        ->middleware('permission:settings.manage')->name('api.v1.settings.index');

    Route::patch('/', [SettingsController::class, 'update'])
        ->middleware('permission:settings.manage')->name('api.v1.settings.update');
});

/*
|--------------------------------------------------------------------------
| Suppression / DNC (Phase 19 admin surface, brought forward)
|--------------------------------------------------------------------------
| Suppression has been written since Phase 7 - by `Not Interested` and by call
| outcomes - with no way to read it back or lift it. These three endpoints
| close that, and nothing more: policy configuration, reporting and the
| inbound-keyword source stay with Phase 19 (T-29).
|
| `dnc.remove` is in Permission::isAudited(), so every removal writes an audit
| entry from the middleware before the controller runs (SEC-AUD-02).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->prefix('dnc')->group(function () {
    Route::get('/', [DncController::class, 'index'])
        ->middleware('permission:dnc.view')->name('api.v1.dnc.index');

    Route::post('/', [DncController::class, 'store'])
        ->middleware('permission:dnc.create')->name('api.v1.dnc.store');

    // Deactivates rather than deletes - the audit trail is the point.
    Route::delete('/{dncEntry}', [DncController::class, 'destroy'])
        ->middleware('permission:dnc.remove')->name('api.v1.dnc.destroy');
});

/*
|--------------------------------------------------------------------------
| Calls (Phase 9)
|--------------------------------------------------------------------------
| Cross-lead call history, and the outcome endpoint the Android app posts to
| when a handset finishes a call (ADR-B).
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->prefix('calls')->group(function () {
    Route::get('/', [CallController::class, 'history'])
        ->middleware('permission:calls.view')->name('api.v1.calls.history');

    Route::patch('/{call}', [CallController::class, 'update'])
        ->middleware('permission:calls.create')->name('api.v1.calls.update');
});

/*
|--------------------------------------------------------------------------
| Auto dialer (Phase 10)
|--------------------------------------------------------------------------
| The dialer decides which lead is next and whether it may be called; the
| device places the call (ADR-B). `next` is the whole module.
*/
Route::middleware(['auth:sanctum', 'throttle:api-standard'])->prefix('dialer')->group(function () {
    // Survives a page reload - the queue is a table, not a browser variable.
    Route::get('/current', [DialerController::class, 'current'])
        ->middleware('permission:dialer.use')->name('api.v1.dialer.current');

    Route::post('/sessions', [DialerController::class, 'store'])
        ->middleware('permission:dialer.use')->name('api.v1.dialer.start');

    Route::get('/sessions/{autoDialerSession}', [DialerController::class, 'show'])
        ->middleware('permission:dialer.use')->name('api.v1.dialer.show');

    Route::post('/sessions/{autoDialerSession}/next', [DialerController::class, 'next'])
        ->middleware('permission:dialer.use')->name('api.v1.dialer.next');
    Route::post('/sessions/{autoDialerSession}/pause', [DialerController::class, 'pause'])
        ->middleware('permission:dialer.use')->name('api.v1.dialer.pause');
    Route::post('/sessions/{autoDialerSession}/resume', [DialerController::class, 'resume'])
        ->middleware('permission:dialer.use')->name('api.v1.dialer.resume');
    Route::post('/sessions/{autoDialerSession}/stop', [DialerController::class, 'stop'])
        ->middleware('permission:dialer.use')->name('api.v1.dialer.stop');
});

/*
|--------------------------------------------------------------------------
| Phase 11+ : recordings, channels, campaigns
| ... see docs/API_DOCUMENTATION.md §11
|--------------------------------------------------------------------------
*/
