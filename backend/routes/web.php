<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\PageController;
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

    Route::get('/imports', [PageController::class, 'imports'])
        ->middleware('permission:leads.import')->name('web.imports');
});
