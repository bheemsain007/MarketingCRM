<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Automates the pre-production checklist (DEPLOYMENT §10, SEC-OPS-*).
 *
 * The checklist was a list of tick boxes in a markdown file, which is the same
 * as no checklist at all on the third deploy at eleven at night. Every item
 * below is one somebody has shipped broken somewhere: `APP_DEBUG=true` on a
 * public host (SEC-CFG-03) hands database credentials to anyone who can force
 * an error, an uncached config on shared hosting costs a file read per request,
 * a session cookie without `Secure` travels in clear over any downgraded
 * request, and a seeded demo password nobody changed is a working login.
 *
 * **Exit code is the product here, not the pretty output.** It returns non-zero
 * on any failure so a deploy script can end with
 *
 *     php artisan crm:production-check || exit 1
 *
 * and refuse to finish. A check nobody reads is worth nothing; a check that
 * stops the deploy is worth the whole file.
 *
 * Warnings are separated from failures on purpose. A failure is "this is
 * definitely wrong in production". A warning is "this is probably wrong, and I
 * cannot see enough from inside the application to be sure" - whether a cron
 * entry exists on the host, for example, is knowable only through its effects.
 * Conflating the two produces a command people learn to run with `|| true`.
 */
class ProductionCheckCommand extends Command
{
    protected $signature = 'crm:production-check
                            {--strict : Treat warnings as failures too}';

    protected $description = 'Verify the pre-production checklist (DEPLOYMENT §10) and fail if anything is wrong';

    /** @var array<int, array{status: string, name: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->line('Pre-production check - DEPLOYMENT §10');
        $this->newLine();

        $this->checkDebug();
        $this->checkAppKey();
        $this->checkEnvironment();
        $this->checkConfigCache();
        $this->checkHttps();
        $this->checkSessionCookie();
        $this->checkStorage();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkDatabase();
        $this->checkDefaultCredentials();
        $this->checkSecurityHeaders();

        return $this->render();
    }

    // -----------------------------------------------------------------------
    // Checks
    // -----------------------------------------------------------------------

    private function checkDebug(): void
    {
        // The single highest-value line in this file. A debug page renders the
        // full environment - DB password, provider keys - to whoever triggered
        // the error (SEC-CFG-03).
        config('app.debug')
            ? $this->bad('APP_DEBUG', 'Debug mode is ON. A stack trace exposes every credential in .env.')
            : $this->ok('APP_DEBUG', 'off');
    }

    private function checkAppKey(): void
    {
        $key = (string) config('app.key');

        if ($key === '') {
            $this->bad('APP_KEY', 'Not set. Encrypted settings and 2FA secrets cannot be read or written.');

            return;
        }

        $this->ok('APP_KEY', 'set');
    }

    private function checkEnvironment(): void
    {
        app()->environment('production')
            ? $this->ok('APP_ENV', 'production')
            : $this->iffy('APP_ENV', 'Is "'.app()->environment().'", not "production". Expected on a live host.');
    }

    private function checkConfigCache(): void
    {
        /*
         * Not just performance. Every service in this codebase reads config()
         * and never env() precisely because env() returns null once config is
         * cached - so an install running UNCACHED is one `config:cache` away
         * from behaving differently, and it is better to find that here than
         * on the deploy that finally runs it.
         */
        file_exists(App::getCachedConfigPath())
            ? $this->ok('Config cache', 'built')
            : $this->iffy('Config cache', 'Not built. Run `php artisan config:cache` (DEPLOYMENT §6 step 6).');

        file_exists(App::getCachedRoutesPath())
            ? $this->ok('Route cache', 'built')
            : $this->iffy('Route cache', 'Not built. Run `php artisan route:cache`.');
    }

    private function checkHttps(): void
    {
        $url = (string) config('app.url');

        str_starts_with($url, 'https://')
            ? $this->ok('APP_URL', $url)
            : $this->bad('APP_URL', 'Is "'.$url.'". Signed recording URLs and password reset links are built from this, so http:// mails an insecure link to every user (SEC-FILE-03).');
    }

    private function checkSessionCookie(): void
    {
        config('session.secure')
            ? $this->ok('SESSION_SECURE_COOKIE', 'on')
            : $this->bad('SESSION_SECURE_COOKIE', 'Off. The session cookie will be sent over plain HTTP, where anything on the path can read it.');

        config('session.http_only')
            ? $this->ok('SESSION_HTTP_ONLY', 'on')
            : $this->bad('SESSION_HTTP_ONLY', 'Off. JavaScript can read the session cookie, so any XSS becomes account takeover.');

        // `lax` is the floor, not the ideal: it still blocks the cross-site
        // POST that CSRF needs, while `none` disables the protection entirely.
        in_array(config('session.same_site'), ['lax', 'strict'], true)
            ? $this->ok('SESSION_SAME_SITE', (string) config('session.same_site'))
            : $this->bad('SESSION_SAME_SITE', 'Is "'.var_export(config('session.same_site'), true).'". Use lax or strict.');
    }

    private function checkStorage(): void
    {
        foreach (['framework/sessions', 'framework/views', 'logs'] as $path) {
            $full = storage_path($path);

            is_dir($full) && is_writable($full)
                ? $this->ok('storage/'.$path, 'writable')
                : $this->bad('storage/'.$path, 'Missing or not writable. Laravel cannot render a page or write a log line.');
        }

        /*
         * Recordings and lead imports are bulk PII and must not be on the
         * `public` disk, which is a symlinked directory served directly by
         * Apache with no signature check (SEC-FILE-03, SEC-PII-05).
         */
        foreach (['recordings' => 'crm.recordings.disk', 'lead imports' => 'crm.lead_import.disk'] as $label => $key) {
            $disk = (string) config($key, '');

            $disk === 'public'
                ? $this->bad($label.' disk', 'Set to "public" - the files are served directly by the web server, bypassing signed URLs.')
                : $this->ok($label.' disk', $disk ?: 'default');
        }
    }

    private function checkQueue(): void
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            // `sync` runs jobs inside the web request. A 50,000-recipient
            // campaign would run in the browser's request and time out.
            $this->bad('Queue', 'Connection is "sync" - queued work would run inside the HTTP request (DEPLOYMENT §4).');

            return;
        }

        $this->ok('Queue', $connection);

        if ($connection === 'database') {
            try {
                $this->ok('Failed jobs', DB::table('failed_jobs')->count().' recorded');
            } catch (Throwable $e) {
                $this->bad('Failed jobs table', 'Not readable: '.$e->getMessage().'. Run `php artisan queue:failed-table` and migrate.');
            }

            $this->checkQueueBacklog();
        }
    }

    /**
     * Report work that is queued but never picked up (ARCHITECTURE §4).
     *
     * A worker started without `--queue` drains only `default`, and every job
     * here names a queue instead. Nothing throws in that state: the API keeps
     * returning "queued", `failed_jobs` stays empty, and the rows simply
     * accumulate - so the only visible symptom is old work that never moved.
     * That is exactly what this measures, which also makes it indifferent to
     * the cause: a stopped worker and a mistyped queue name look the same, and
     * both need the same person to look.
     */
    private function checkQueueBacklog(): void
    {
        $stale = now()->subMinutes(max(1, (int) config('crm.queue_backlog_alert_minutes')));

        try {
            /** @var array<string, object{waiting: int, oldest: string|null}> $depths */
            $depths = DB::table('jobs')
                ->selectRaw('queue, COUNT(*) as waiting, MIN(created_at) as oldest')
                ->groupBy('queue')
                ->get()
                ->keyBy('queue')
                ->all();
        } catch (Throwable $e) {
            $this->bad('Jobs table', 'Not readable: '.$e->getMessage().'. Run `php artisan queue:table` and migrate.');

            return;
        }

        foreach ($depths as $queue => $row) {
            $waiting = (int) $row->waiting;

            // `created_at` on the jobs table is a unix timestamp, not a date.
            $oldest = $row->oldest !== null ? Carbon::createFromTimestamp((int) $row->oldest) : null;

            if ($oldest !== null && $oldest->lessThan($stale)) {
                $this->bad(
                    'Queue "'.$queue.'"',
                    $waiting.' job(s) waiting, oldest queued '.$oldest->diffForHumans().
                    ' - no worker is draining this queue. Check the worker names it (DEPLOYMENT §4).'
                );

                continue;
            }

            $this->ok('Queue "'.$queue.'"', $waiting.' waiting');
        }

        // A queue nobody has dispatched to yet has no row at all, which is not
        // a fault - but naming it keeps the expected set visible next to the
        // measured one, so a queue that disappears from the code is noticed.
        $idle = array_diff((array) config('crm.queues', []), array_keys($depths));

        if ($idle !== []) {
            $this->ok('Queues empty', implode(', ', $idle));
        }
    }

    private function checkScheduler(): void
    {
        $last = SchedulerHeartbeatCommand::lastBeat();

        if ($last === null) {
            $this->iffy('Scheduler', 'No heartbeat recorded. Either the cron entry from DEPLOYMENT §5 is missing, or it has not ticked yet on a fresh install.');

            return;
        }

        SchedulerHeartbeatCommand::isHealthy()
            ? $this->ok('Scheduler', 'heartbeat '.$last->diffForHumans())
            : $this->bad('Scheduler', 'Last heartbeat '.$last->diffForHumans().'. Reminders and both retention purges are not running (SEC-PII-05).');
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();

            $this->ok('Database', 'reachable ('.config('database.default').')');
        } catch (Throwable $e) {
            $this->bad('Database', 'Unreachable: '.$e->getMessage());
        }
    }

    /**
     * Seeded demo credentials are a working login, not a placeholder.
     *
     * Checked by trying the passwords the seeders use rather than by looking
     * for particular email addresses - a renamed demo account is still a demo
     * account. Only active users are examined; a disabled one cannot sign in.
     */
    private function checkDefaultCredentials(): void
    {
        $weak = ['password', 'password123', 'secret', 'Password123!', 'admin', 'changeme'];
        $found = [];

        try {
            User::query()->where('is_active', true)->get(['id', 'email', 'password'])
                ->each(function (User $user) use ($weak, &$found) {
                    foreach ($weak as $candidate) {
                        if (Hash::check($candidate, $user->password)) {
                            $found[] = $user->email;

                            return;
                        }
                    }
                });
        } catch (Throwable $e) {
            $this->iffy('Default credentials', 'Could not be checked: '.$e->getMessage());

            return;
        }

        $found === []
            ? $this->ok('Default credentials', 'none found')
            : $this->bad('Default credentials', count($found).' active account(s) still use a seeded password: '.implode(', ', $found));
    }

    private function checkSecurityHeaders(): void
    {
        config('security.headers.enabled')
            ? $this->ok('Security headers', 'enabled (SEC-OPS-02)')
            : $this->bad('Security headers', 'Disabled via SECURITY_HEADERS_ENABLED.');

        if (! config('security.csp.enabled')) {
            $this->iffy('CSP', 'Disabled. See T-39 for what the default policy does and does not buy.');
        } elseif (config('security.csp.report_only')) {
            $this->iffy('CSP', 'Report-Only: violations are reported and nothing is blocked.');
        } else {
            $this->ok('CSP', 'enforcing');
        }
    }

    // -----------------------------------------------------------------------
    // Result collection
    // -----------------------------------------------------------------------

    private function ok(string $name, string $detail): void
    {
        $this->results[] = ['status' => 'pass', 'name' => $name, 'detail' => $detail];
    }

    private function bad(string $name, string $detail): void
    {
        $this->results[] = ['status' => 'fail', 'name' => $name, 'detail' => $detail];
    }

    /** Named with a trailing underscore: `warn` is Command's own output method. */
    private function iffy(string $name, string $detail): void
    {
        $this->results[] = ['status' => 'warn', 'name' => $name, 'detail' => $detail];
    }

    private function render(): int
    {
        foreach ($this->results as $result) {
            $line = sprintf('  %-28s %s', $result['name'], $result['detail']);

            match ($result['status']) {
                'pass' => $this->line('<fg=green>PASS</> '.$line),
                'warn' => $this->line('<fg=yellow>WARN</> '.$line),
                default => $this->line('<fg=red>FAIL</> '.$line),
            };
        }

        $failures = count(array_filter($this->results, fn ($r) => $r['status'] === 'fail'));
        $warnings = count(array_filter($this->results, fn ($r) => $r['status'] === 'warn'));

        $this->newLine();
        $this->line(sprintf(
            '%d checks - %d failed, %d warning(s).',
            count($this->results),
            $failures,
            $warnings,
        ));

        if ($failures > 0 || ($warnings > 0 && $this->option('strict'))) {
            $this->newLine();
            $this->error('Not ready for production.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Pre-production checklist passed.');

        return self::SUCCESS;
    }
}
