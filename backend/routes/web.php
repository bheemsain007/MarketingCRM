<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\PasswordResetController;
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

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('web.dashboard');

    // No permission gate: changing your own password is not a privilege.
    Route::get('/account', [PageController::class, 'account'])->name('web.account');

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

    Route::get('/products', [PageController::class, 'products'])
        ->middleware('permission:products.view')->name('web.products');

    // The Accounts role's screen (ROLE-05). `payments.view` opens it; the
    // record and refund controls are drawn only for those who hold the
    // corresponding powers.
    Route::get('/payments', [PageController::class, 'payments'])
        ->middleware('permission:payments.view')->name('web.payments');

    Route::get('/imports', [PageController::class, 'imports'])
        ->middleware('permission:leads.import')->name('web.imports');
});
