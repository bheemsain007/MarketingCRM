<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\PasswordResetController;
use App\Http\Controllers\Web\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web CRM (ADR-A, Phase 8)
|--------------------------------------------------------------------------
| One Laravel application. Blade renders page shells; the page's jQuery calls
| the same app's /api/v1/* endpoints, same-origin and session-authenticated.
|
| These routes render pages only - they never carry business logic. Every
| write goes through the API, so there is one implementation of each rule and
| the browser and the Flutter app cannot drift apart.
*/

Route::get('/', fn () => redirect()->route('web.dashboard'))->name('web.home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('web.login');

    // Same 5/min limiter as the API login, for the same reason (SEC-AUTH-03).
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:api-auth')
        ->name('web.login.attempt');

    /*
     * Password recovery (SEC-AUTH-06). The only account-recovery path there is:
     * no administrator screen sets another person's password, because whoever
     * can do that can sign in as them and every audit entry afterwards names
     * the wrong human.
     *
     * Both POSTs are throttled - one mails a token and the other consumes one,
     * and both are worth guessing at (SEC-AUTH-03). They use their OWN limiter
     * rather than login's: per-account throttling means anyone who knows an
     * address can fill that account's bucket, and sharing one bucket with login
     * would turn that into a total lockout with the escape hatch jammed shut
     * too.
     */
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])
        ->name('web.password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:password-recovery')
        ->name('web.password.email');

    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])
        ->name('web.password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-recovery')
        ->name('web.password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('web.logout');

/*
 * Second-factor challenge (SEC-AUTH-07, T-09).
 *
 * Behind `auth` but NOT behind RequireTwoFactorChallenge's redirect - a pending
 * session IS authenticated, and the middleware allow-lists these two names, or
 * the only page the user is permitted to reach would redirect to itself.
 *
 * Throttled on the same limiter as login: a six-digit code is 10^6 guesses, and
 * an unthrottled challenge form turns 2FA into a slow password (SEC-AUTH-03).
 */
Route::middleware('auth')->group(function () {
    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])
        ->name('web.two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:api-auth')
        ->name('web.two-factor.verify');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('web.dashboard');

    // No permission gate: changing your own password is not a privilege.
    Route::get('/account', [PageController::class, 'account'])->name('web.account');

    /*
     * Own-account 2FA (SEC-AUTH-07). No permission gate for the same reason the
     * password form has none: securing your own account is not a privilege.
     * Eligibility - Admin and Super Admin - is decided by TwoFactorService, so
     * the page renders an explanation for everyone else rather than a 403.
     */
    Route::get('/account/two-factor', [TwoFactorController::class, 'show'])
        ->name('web.account.two-factor');
    Route::post('/account/two-factor', [TwoFactorController::class, 'begin'])
        ->name('web.account.two-factor.begin');
    Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware('throttle:api-auth')
        ->name('web.account.two-factor.confirm');
    Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])
        ->name('web.account.two-factor.recovery');
    Route::post('/account/two-factor/disable', [TwoFactorController::class, 'disable'])
        ->middleware('throttle:api-auth')
        ->name('web.account.two-factor.disable');

    Route::get('/leads', [PageController::class, 'leads'])
        ->middleware('permission:leads.view')->name('web.leads');

    // Static segment before {lead}, or "create" is bound as a lead id and 404s.
    Route::get('/leads/create', [PageController::class, 'leadCreate'])
        ->middleware('permission:leads.create')->name('web.leads.create');

    Route::get('/leads/{lead}', [PageController::class, 'lead'])
        ->middleware('permission:leads.view')->name('web.leads.show');
    Route::get('/leads/{lead}/edit', [PageController::class, 'leadEdit'])
        ->middleware('permission:leads.update')->name('web.leads.edit');

    Route::get('/assignments', [PageController::class, 'assignments'])
        ->middleware('permission:leads.assign')->name('web.assignments');

    Route::get('/dialer', [PageController::class, 'dialer'])
        ->middleware('permission:dialer.use')->name('web.dialer');

    // Viewing the queue needs only leads.view; resolving one needs
    // leads.archive, which the API enforces (T-64).
    Route::get('/lead-duplicates', [PageController::class, 'leadDuplicates'])
        ->middleware('permission:leads.view')->name('web.leads.duplicates');

    Route::get('/campaigns', [PageController::class, 'campaigns'])
        ->middleware('permission:campaigns.view')->name('web.campaigns');
    Route::get('/campaigns/create', [PageController::class, 'campaignCreate'])
        ->middleware('permission:campaigns.manage')->name('web.campaigns.create');
    Route::get('/campaigns/{campaign}', [PageController::class, 'campaign'])
        ->middleware('permission:campaigns.view')->name('web.campaigns.show');

    // Not data-scoped, like the API behind it: a business dashboard is the
    // organisation-wide view (FR-RPT-03, T-60).
    Route::get('/reports', [PageController::class, 'reports'])
        ->middleware('permission:reports.business')->name('web.reports');

    // The people view, gated on its own permission: reports.telecaller is who
    // gets paid, reports.business is how the business is doing (Phase 26).
    Route::get('/reports/telecallers', [PageController::class, 'telecallerReports'])
        ->middleware('permission:reports.telecaller')->name('web.reports.telecallers');

    // `users.view` opens the page; the create form and the role control are
    // drawn only for those holding users.manage / roles.manage (SEC-AUTHZ-05).
    Route::get('/users', [PageController::class, 'users'])
        ->middleware('permission:users.view')->name('web.users');

    Route::get('/settings', [PageController::class, 'settings'])
        ->middleware('permission:settings.manage')->name('web.settings');

    Route::get('/dnc', [PageController::class, 'dnc'])
        ->middleware('permission:dnc.view')->name('web.dnc');

    /*
     * The compliance trail (SEC-AUD-02). Read-only, like the API behind it -
     * `audit_logs` is append-only, so there is no write screen to gate.
     */
    Route::get('/audit', [PageController::class, 'audit'])
        ->middleware('permission:audit.view')->name('web.audit');

    Route::get('/products', [PageController::class, 'products'])
        ->middleware('permission:products.view')->name('web.products');

    // The Accounts role's screen (ROLE-05). `payments.view` opens it; the
    // record and refund controls are drawn only for those who hold the
    // corresponding powers.
    Route::get('/payments', [PageController::class, 'payments'])
        ->middleware('permission:payments.view')->name('web.payments');

    Route::get('/imports', [PageController::class, 'imports'])
        ->middleware('permission:leads.import')->name('web.imports');

    /*
     * Your own notifications, so no permission gate - for the same reason
     * /account has none (FR-NOTIF-03).
     */
    Route::get('/notifications', [PageController::class, 'notifications'])
        ->name('web.notifications');

    // `templates.view` opens the page; authoring controls are drawn only for
    // templates.manage (FR-COMM-02).
    Route::get('/templates', [PageController::class, 'templates'])
        ->middleware('permission:templates.view')->name('web.templates');

    // The "what do I owe someone today" screen, distinct from the per-lead
    // tab on the lead page (FR-FUP-04).
    Route::get('/follow-ups', [PageController::class, 'followUps'])
        ->middleware('permission:follow_ups.view')->name('web.follow-ups');

    // Static segment, and /dnc takes no parameter, so no binding ambiguity.
    Route::get('/dnc/skips', [PageController::class, 'dncSkips'])
        ->middleware('permission:dnc.view')->name('web.dnc.skips');

    Route::get('/calls', [PageController::class, 'calls'])
        ->middleware('permission:calls.view')->name('web.calls');

    // Gated on leads.view rather than messages.send, matching the API behind
    // it: reading what was sent is a lead read, not a send.
    Route::get('/messages', [PageController::class, 'messages'])
        ->middleware('permission:leads.view')->name('web.messages');

    // Tags are organisation reference data, like the settings they sit
    // beside - anyone with leads.update may apply one, but shaping the
    // vocabulary is configuration.
    Route::get('/tags', [PageController::class, 'tags'])
        ->middleware('permission:settings.manage')->name('web.tags');
});
