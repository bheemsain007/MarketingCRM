<?php

namespace App\Console\Commands;

use App\Enums\CallStatus;
use App\Enums\Channel;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Queue\WorkerOptions;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * T-43 (docs/TODO.md, FR-RPT-05) - a reusable load-test harness.
 *
 * WHY AN ARTISAN COMMAND, NOT k6: this machine has no admin rights to
 * install a system package, and downloading and executing an unfamiliar
 * third-party binary is the kind of supply-chain step this project avoids
 * everywhere else. Guzzle (async-capable HTTP client) is already a
 * first-party Composer dependency, and driving the ACTUAL configured queue
 * driver end to end - not a synthetic stand-in - matters here specifically
 * because `.env.loadtest` deliberately runs `QUEUE_CONNECTION=database`, the
 * same driver production uses on Hostinger shared hosting (DEPLOYMENT §3A),
 * not the Redis ARCHITECTURE §4 describes as the queue backend. That gap
 * between the two documents is itself one of this run's findings.
 *
 * WHY IN-PROCESS, NOT A `queue:work` SUBPROCESS: `drainQueueInProcess()`
 * calls `Illuminate\Queue\Worker` directly - the exact class, job-reservation
 * SQL and retry/backoff logic `queue:work` itself calls - so it measures the
 * real `database` driver's throughput without the overhead of managing a
 * second process's lifecycle from within this command. This is a
 * simplification, not a workaround for a broken subprocess: a real
 * `queue:work` subprocess was independently verified to run cleanly against
 * this same MariaDB instance with zero errors across a full drain (see
 * docs/LOAD_TEST_RESULTS.md §5) once nothing else was touching the disposable
 * database at the same time.
 *
 * That last clause is the actual finding, and it is the reason for
 * `withLoadTestLock()` below. An earlier run of this harness genuinely did see
 * intermittent "table doesn't exist" errors and attributed them to instability
 * in this machine's MariaDB instance (echoing T-48's documented `mysql.db`
 * corruption). That diagnosis was wrong. The real cause, confirmed by
 * reproducing a clean run in isolation: **two processes were pointed at the
 * same disposable `marketing_crm_loadtest` database at once** - a manual
 * invocation and a separately-running harness session - each truncating and
 * reseeding tables the other was mid-query against. A single connection
 * against an unshared database never reproduced the error. See
 * docs/LOAD_TEST_RESULTS.md §5 for the full account; it is recorded here so
 * the wrong diagnosis is not silently rediscovered.
 *
 * NEVER point this at a real database. `guardAgainstRealDatabase()` refuses
 * to run unless the connected database name ends in `_loadtest`.
 * `withLoadTestLock()` additionally refuses to run if ANOTHER instance of this
 * command already holds the disposable database - the actual root cause
 * above, made structurally impossible rather than merely documented. The
 * documented entry point is `tools/loadtest/run.ps1`, which creates
 * `marketing_crm_loadtest`, runs migrations against it, runs this command,
 * then drops it again.
 *
 * Two scenarios, matching the two risk points named in ARCHITECTURE §4/§12
 * and PROJECT_REQUIREMENTS FR-RPT-05:
 *
 *   campaign  - seeds a synthetic audience, starts a campaign, and drives
 *               the real `database` queue driver to fan it out and drain
 *               it, timing each stage (FR-CAMP-05).
 *   dashboard - seeds multi-month history, pre-aggregates it
 *               (`crm:aggregate-daily-reports`), then fires real HTTP
 *               requests at `/api/v1/reports/summary` and `/dashboard`
 *               for a fully-past period (should hit
 *               `report_daily_aggregates`) and a period touching today
 *               (must fall back to a live query) - the exact distinction
 *               FR-RPT-05 exists to make fast.
 */
class LoadTestCommand extends Command
{
    protected $signature = 'crm:load-test
                            {scenario=all : campaign|dashboard|all}
                            {--leads=8000 : Synthetic audience size for the campaign scenario}
                            {--days=180 : Days of history to seed for the dashboard scenario}
                            {--leads-per-day=60 : Leads/day seeded for the dashboard scenario}
                            {--repeats=30 : HTTP requests fired per dashboard measurement}
                            {--port=8781 : Port for the throwaway `php artisan serve` used by the dashboard scenario}
                            {--force : Skip the disposable-database name guard (dangerous - do not use against a real database)}';

    protected $description = 'T-43 load-test harness: campaign fan-out throughput and dashboard response time (FR-RPT-05)';

    /** @var array<string, mixed> */
    private array $results = [];

    /** Transient DB errors absorbed by drainQueueInProcess()'s retry - see its docblock. */
    private int $transientQueueErrors = 0;

    public function handle(): int
    {
        if (! $this->guardAgainstRealDatabase()) {
            return self::FAILURE;
        }

        $lockName = 'crm-load-test:'.config('database.connections.mysql.database');
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);

        if (! $lock || (int) $lock->acquired !== 1) {
            $this->components->error(
                'Another crm:load-test run already holds this database. Two runs sharing one '.
                '"_loadtest" database is exactly what produced the false "MariaDB is fragile" '.
                'finding this command\'s docblock records - wait for the other run to finish, or '.
                'give this one its own database.'
            );

            return self::FAILURE;
        }

        try {
            $this->ensureBaselineData();

            $scenario = $this->argument('scenario');

            if (! in_array($scenario, ['campaign', 'dashboard', 'all'], true)) {
                $this->components->error('Scenario must be one of: campaign, dashboard, all.');

                return self::FAILURE;
            }

            if (in_array($scenario, ['campaign', 'all'], true)) {
                $this->components->info('Scenario: campaign throughput (FR-CAMP-05)');
                $this->runCampaignScenario();
            }

            if (in_array($scenario, ['dashboard', 'all'], true)) {
                $this->components->info('Scenario: dashboard response (FR-RPT-05)');
                $this->runDashboardScenario();
            }

            $this->printSummary();

            return self::SUCCESS;
        } finally {
            DB::statement('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    // -------------------------------------------------------------------
    // Safety
    // -------------------------------------------------------------------

    /**
     * This command truncates tables and fires a real queue worker. Both are
     * fine against a disposable database and catastrophic against a real
     * one - the guard is the whole reason `_loadtest` is a naming
     * convention rather than a suggestion.
     */
    private function guardAgainstRealDatabase(): bool
    {
        $database = (string) config('database.connections.mysql.database');

        if ($this->option('force')) {
            $this->components->warn("--force given: skipping the disposable-database check for \"{$database}\".");

            return true;
        }

        if (! str_ends_with($database, '_loadtest')) {
            $this->components->error(sprintf(
                'Refusing to run against "%s". This command truncates tables and runs a real '.
                'queue worker - it must only run against a disposable database whose name ends '.
                'in "_loadtest". Use tools/loadtest/run.ps1, which creates one and passes '.
                '--env=loadtest automatically.',
                $database,
            ));

            return false;
        }

        return true;
    }

    /**
     * Idempotent, so a manual re-run of this command against an already
     * migrated loadtest database does not need a separate seed step.
     */
    private function ensureBaselineData(): void
    {
        $this->callSilently('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => ProductSeeder::class, '--force' => true]);
    }

    // -------------------------------------------------------------------
    // Scenario A: campaign throughput
    // -------------------------------------------------------------------

    private function runCampaignScenario(): void
    {
        $leadsCount = max(100, (int) $this->option('leads'));
        $this->transientQueueErrors = 0;

        $this->truncateFor('campaign');

        $seedStart = microtime(true);
        $this->bulkSeedLeads($leadsCount);
        $seedSeconds = microtime(true) - $seedStart;

        $campaign = app(CampaignService::class)->create([
            'name' => 'Load test '.now()->toDateTimeString(),
            'channel' => Channel::Sms->value,
            'audience_filters' => [],
        ]);

        // materialiseAudience() (private) runs synchronously inside start();
        // only the fan-out job dispatch is deferred to the queue. Timing
        // start() end to end IS timing the audience build - the dispatch()
        // call itself is one row insert and negligible next to it.
        $buildStart = microtime(true);
        $campaign = app(CampaignService::class)->start($campaign);
        $buildSeconds = microtime(true) - $buildStart;

        // Stage 1: process exactly the ONE DispatchCampaign job. This is the
        // fan-out - it turns `total_targeted` recipient rows into that many
        // queued SendCampaignMessage jobs, and nothing else runs yet because
        // draining stops after exactly one job.
        $fanoutStart = microtime(true);
        $this->drainQueueInProcess(1);
        $fanoutSeconds = microtime(true) - $fanoutStart;

        $campaign->refresh();
        $enqueued = $campaign->total_queued;

        // Stage 2: drain everything else - every SendCampaignMessage job,
        // plus the SendMessage job each non-skipped one dispatches in turn.
        // `jobsTableNextId()` brackets the MariaDB auto-increment counter on
        // `jobs`, which only ever goes up (rows are hard-deleted on success,
        // not the counter) - so the delta is the TRUE number of queue jobs
        // that passed through the table during this stage, fan-out-of-the-
        // fan-out included, not just the recipient count.
        $jobsBefore = $this->jobsTableNextId();
        $drainStart = microtime(true);
        $this->drainQueueInProcess(null);
        $drainSeconds = microtime(true) - $drainStart;
        $jobsAfter = $this->jobsTableNextId();

        $campaign->refresh();
        $processed = $campaign->total_sent + $campaign->total_skipped + $campaign->total_failed;
        $rawJobsChurned = max(0, $jobsAfter - $jobsBefore);
        $failedJobs = (int) DB::table('failed_jobs')->count();

        $this->results['campaign'] = [
            'leads_seeded' => $leadsCount,
            'seed_seconds' => round($seedSeconds, 3),
            'seed_rate_per_sec' => $this->rate($leadsCount, $seedSeconds),
            'audience_targeted' => $campaign->total_targeted,
            'audience_build_seconds' => round($buildSeconds, 3),
            'audience_build_rate_per_sec' => $this->rate($campaign->total_targeted, $buildSeconds),
            'fanout_seconds' => round($fanoutSeconds, 3),
            'fanout_enqueued' => $enqueued,
            'fanout_rate_per_sec' => $this->rate($enqueued, $fanoutSeconds),
            'drain_seconds' => round($drainSeconds, 3),
            'recipients_processed' => $processed,
            'recipients_sent' => $campaign->total_sent,
            'recipients_skipped' => $campaign->total_skipped,
            'recipients_failed' => $campaign->total_failed,
            'recipient_rate_per_sec' => $this->rate($processed, $drainSeconds),
            'raw_jobs_churned' => $rawJobsChurned,
            'raw_job_rate_per_sec' => $this->rate($rawJobsChurned, $drainSeconds),
            'failed_jobs_table_count' => $failedJobs,
            'transient_queue_errors' => $this->transientQueueErrors,
        ];
    }

    /**
     * @return void
     */
    private function bulkSeedLeads(int $count): void
    {
        $now = now();

        foreach (array_chunk(range(0, $count - 1), 1000) as $chunk) {
            $rows = array_map(fn (int $i) => [
                'tenant_id' => 0,
                'name' => 'Load Test Lead '.$i,
                'phone_e164' => $this->syntheticPhone($i, '+9170'),
                'phone_raw' => $this->syntheticPhone($i, '+9170'),
                'email' => 'campaignload'.$i.'@example.test',
                'city' => 'Bengaluru',
                'country' => 'India',
                'status' => LeadStatus::New->value,
                'temperature' => 'cold',
                'score' => 0,
                'priority' => 0,
                'is_suppressed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            DB::table('leads')->insert($rows);
        }
    }

    // -------------------------------------------------------------------
    // Scenario B: dashboard response
    // -------------------------------------------------------------------

    private function runDashboardScenario(): void
    {
        $this->truncateFor('dashboard');

        $days = max(30, (int) $this->option('days'));
        $perDay = max(1, (int) $this->option('leads-per-day'));
        $timezone = (string) config('crm.timezone', 'UTC');
        $today = Carbon::now($timezone)->startOfDay();

        $product = Product::query()->first();

        if ($product === null) {
            $this->components->error('No product seeded - ProductSeeder did not run.');

            return;
        }

        $totalLeads = 0;
        $seedStart = microtime(true);

        // Fully-past days first, oldest to newest, then "today" last - today
        // deliberately gets seeded too, so the touching-today path has real
        // rows to read live rather than an empty day (FR-RPT-05's live
        // fallback has to do real work in this test, not a trivial one).
        for ($dayOffset = $days; $dayOffset >= 0; $dayOffset--) {
            $day = $today->copy()->subDays($dayOffset);
            $leadIds = $this->seedLeadsForDay($day, $perDay);
            $totalLeads += count($leadIds);

            $this->seedStatusHistoryForDay($day, $leadIds);
            $this->seedCallsForDay($day, $leadIds);
            $this->seedMessagesForDay($day, $leadIds);
            $this->seedSalesForDay($day, $leadIds, $product->id);
        }

        $seedSeconds = microtime(true) - $seedStart;

        $this->results['dashboard']['seed'] = [
            'days' => $days + 1,
            'leads_per_day' => $perDay,
            'leads_total' => $totalLeads,
            'calls_total' => (int) DB::table('calls')->count(),
            'messages_total' => (int) DB::table('messages')->count(),
            'sales_total' => (int) DB::table('sales')->count(),
            'payments_total' => (int) DB::table('payments')->count(),
            'seed_seconds' => round($seedSeconds, 3),
        ];

        // Pre-aggregate every fully-past day (today is deliberately excluded
        // by the command itself - see AggregateDailyReportsCommand).
        $aggStart = microtime(true);
        $this->call('crm:aggregate-daily-reports', [
            '--trailing' => $days + 5,
            '--lookback' => 0,
        ]);
        $aggSeconds = microtime(true) - $aggStart;
        $this->results['dashboard']['aggregation_seconds'] = round($aggSeconds, 3);

        $this->measureHttp();
    }

    private function seedLeadsForDay(Carbon $day, int $count): array
    {
        $createdAt = $day->copy()->addHours(random_int(9, 19))->addMinutes(random_int(0, 59));

        $before = (int) (DB::table('leads')->max('id') ?? 0);

        foreach (array_chunk(range(0, $count - 1), 1000) as $chunk) {
            $rows = array_map(function (int $i) use ($day, $createdAt) {
                // Concatenating rather than adding keeps every day's block of
                // indices visually distinct in a quick eyeball of seeded rows,
                // and stays comfortably inside PHP's integer range for any
                // realistic --days/--leads-per-day combination.
                $globalIndex = (int) ($day->timestamp.$i);

                return [
                    'tenant_id' => 0,
                    'name' => 'Dash Lead '.$globalIndex,
                    'phone_e164' => $this->syntheticPhone($globalIndex, '+9180'),
                    'email' => 'dashlead'.$globalIndex.'@example.test',
                    'city' => 'Mumbai',
                    'country' => 'India',
                    'status' => LeadStatus::New->value,
                    'temperature' => 'cold',
                    'score' => 0,
                    'priority' => 0,
                    'is_suppressed' => false,
                    'assigned_at' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }, $chunk);

            DB::table('leads')->insert($rows);
        }

        $after = (int) DB::table('leads')->max('id');

        // Contiguous IDs: this table was truncated at the start of the
        // scenario and nothing else writes to it concurrently.
        return $after > $before ? range($before + 1, $after) : [];
    }

    private function seedStatusHistoryForDay(Carbon $day, array $leadIds): void
    {
        $rows = [];
        $convertedIds = [];

        foreach ($leadIds as $leadId) {
            // ~70% get contacted, ~25% of those become interested, ~3% of
            // those convert - rough funnel shape, good enough to make every
            // FR-RPT-05 figure non-zero without claiming to model real sales.
            if (random_int(1, 100) > 70) {
                continue;
            }

            $contactedAt = $day->copy()->addHours(random_int(9, 20));
            $rows[] = [
                'lead_id' => $leadId,
                'from_status' => LeadStatus::New->value,
                'to_status' => LeadStatus::Contacted->value,
                'source_channel' => 'call',
                'created_at' => $contactedAt,
            ];

            if (random_int(1, 100) > 25) {
                continue;
            }

            $interestedAt = $contactedAt->copy()->addMinutes(random_int(5, 120));
            $rows[] = [
                'lead_id' => $leadId,
                'from_status' => LeadStatus::Contacted->value,
                'to_status' => LeadStatus::Interested->value,
                'source_channel' => 'call',
                'created_at' => $interestedAt,
            ];

            if (random_int(1, 100) <= 12) {
                $convertedIds[] = $leadId;
            }
        }

        if ($rows !== []) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('lead_status_history')->insert($chunk);
            }
        }

        if ($convertedIds !== []) {
            DB::table('leads')->whereIn('id', $convertedIds)->update(['status' => LeadStatus::Interested->value]);
        }
    }

    private function seedCallsForDay(Carbon $day, array $leadIds): void
    {
        $statuses = [
            CallStatus::Connected->value, CallStatus::Connected->value, CallStatus::Connected->value,
            CallStatus::NoAnswer->value, CallStatus::Busy->value, CallStatus::SwitchedOff->value,
            CallStatus::NotReachable->value,
        ];

        $rows = [];

        foreach ($leadIds as $leadId) {
            for ($c = random_int(1, 2); $c > 0; $c--) {
                $status = $statuses[array_rand($statuses)];
                $startedAt = $day->copy()->addHours(random_int(9, 19))->addMinutes(random_int(0, 59));
                $connected = $status === CallStatus::Connected->value;
                $duration = $connected ? random_int(30, 600) : 0;

                $rows[] = [
                    'tenant_id' => 0,
                    'lead_id' => $leadId,
                    'status' => $status,
                    'started_at' => $startedAt,
                    'ended_at' => $connected ? $startedAt->copy()->addSeconds($duration) : $startedAt,
                    'duration_seconds' => $duration,
                    'dial_source' => 'manual',
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('calls')->insert($chunk);
        }
    }

    private function seedMessagesForDay(Carbon $day, array $leadIds): void
    {
        $channels = [Channel::Sms->value, Channel::Email->value, Channel::WhatsApp->value];
        $rows = [];

        foreach ($leadIds as $leadId) {
            for ($m = random_int(1, 2); $m > 0; $m--) {
                $sentAt = $day->copy()->addHours(random_int(9, 20))->addMinutes(random_int(0, 59));
                $channel = $channels[array_rand($channels)];

                $rows[] = [
                    'tenant_id' => 0,
                    'lead_id' => $leadId,
                    'channel' => $channel,
                    'direction' => 'outbound',
                    'recipient' => $channel === Channel::Email->value
                        ? 'dashlead'.$leadId.'@example.test'
                        : $this->syntheticPhone($leadId, '+9180'),
                    'status' => 'sent',
                    'sent_at' => $sentAt,
                    'created_at' => $sentAt,
                    'updated_at' => $sentAt,
                ];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('messages')->insert($chunk);
        }
    }

    /** A small slice of each day's leads become paying customers. */
    private function seedSalesForDay(Carbon $day, array $leadIds, int $productId): void
    {
        $convertingCount = (int) round(count($leadIds) * 0.03);
        $convertingLeads = array_slice($leadIds, 0, max($convertingCount, $leadIds === [] ? 0 : 1));

        foreach ($convertingLeads as $leadId) {
            $soldAt = $day->copy()->addHours(random_int(10, 18));
            $amount = [15000, 25000, 40000, 60000][array_rand([15000, 25000, 40000, 60000])];

            $customerId = DB::table('customers')->insertGetId([
                'tenant_id' => 0,
                'origin_lead_id' => $leadId,
                'name' => 'Dash Customer '.$leadId,
                'phone_e164' => $this->syntheticPhone($leadId, '+9180'),
                'email' => 'dashlead'.$leadId.'@example.test',
                'country' => 'India',
                'status' => 'active',
                'created_at' => $soldAt,
                'updated_at' => $soldAt,
            ]);

            $opportunityId = DB::table('opportunities')->insertGetId([
                'tenant_id' => 0,
                'lead_id' => $leadId,
                'customer_id' => $customerId,
                'title' => 'Dash Deal '.$leadId,
                'status' => 'won',
                'value' => $amount,
                'currency' => 'INR',
                'closed_at' => $soldAt,
                'created_at' => $soldAt->copy()->subDays(random_int(1, 10)),
                'updated_at' => $soldAt,
            ]);

            $saleId = DB::table('sales')->insertGetId([
                'tenant_id' => 0,
                'opportunity_id' => $opportunityId,
                'customer_id' => $customerId,
                'lead_id' => $leadId,
                'reference' => 'LT-'.$leadId,
                'amount' => $amount,
                'currency' => 'INR',
                'sold_at' => $soldAt,
                'created_at' => $soldAt,
                'updated_at' => $soldAt,
            ]);

            DB::table('payments')->insert([
                'tenant_id' => 0,
                'sale_id' => $saleId,
                'customer_id' => $customerId,
                'lead_id' => $leadId,
                'product_id' => $productId,
                'reference' => 'PAY-'.$leadId,
                'amount' => $amount,
                'currency' => 'INR',
                'status' => 'paid',
                'method' => 'upi',
                'paid_at' => $soldAt,
                'created_at' => $soldAt,
                'updated_at' => $soldAt,
            ]);

            DB::table('lead_status_history')->insert([
                'lead_id' => $leadId,
                'from_status' => LeadStatus::Interested->value,
                'to_status' => LeadStatus::Converted->value,
                'source_channel' => 'manual',
                'created_at' => $soldAt,
            ]);

            DB::table('leads')->where('id', $leadId)->update(['status' => LeadStatus::Converted->value]);
        }
    }

    // -------------------------------------------------------------------
    // HTTP measurement (dashboard scenario)
    // -------------------------------------------------------------------

    private function measureHttp(): void
    {
        $port = (int) $this->option('port');
        $repeats = max(5, (int) $this->option('repeats'));

        $role = Role::where('name', RoleName::Admin->value)->first();

        if ($role === null) {
            $this->components->error('Admin role not seeded - RolePermissionSeeder did not run.');

            return;
        }

        $email = 'loadtest-admin@example.test';
        $password = 'LoadTest#12345';

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Load Test Admin',
                'password' => Hash::make($password),
            ],
        );
        // Not mass-assignable (App\Models\User's $fillable is deliberately
        // narrow) - set directly rather than widening it for a test fixture.
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->sync([$role->id]);

        $php = (new PhpExecutableFinder())->find(false) ?: 'php';

        $server = new Process([$php, 'artisan', 'serve', '--env=loadtest', '--port='.$port], base_path());
        $server->setTimeout(null);
        $server->start();

        try {
            $this->waitForServer($port);

            $baseUri = 'http://127.0.0.1:'.$port;
            $jar = new CookieJar();
            $client = new Client([
                'base_uri' => $baseUri,
                'cookies' => $jar,
                'http_errors' => false,
                'timeout' => 15,
            ]);

            // --- Web session login (for /dashboard) --------------------------
            $loginPage = $client->get('/login');
            preg_match('/name="_token" value="([^"]+)"/', (string) $loginPage->getBody(), $tokenMatch);
            $csrfToken = $tokenMatch[1] ?? null;

            if ($csrfToken !== null) {
                $client->post('/login', [
                    'form_params' => ['_token' => $csrfToken, 'email' => $email, 'password' => $password],
                    'allow_redirects' => false,
                ]);
            } else {
                $this->components->warn('No CSRF token found on /login - skipping the web /dashboard measurement.');
            }

            // --- API token login (for /api/v1/reports/summary) ---------------
            $apiLogin = $client->post('/api/v1/auth/login', [
                'json' => ['email' => $email, 'password' => $password],
            ]);
            $apiBody = json_decode((string) $apiLogin->getBody(), true);
            $apiToken = $apiBody['data']['token'] ?? null;

            if ($apiToken === null) {
                $this->components->error('API login failed - cannot measure /api/v1/reports/summary. Response: '.substr((string) $apiLogin->getBody(), 0, 300));

                return;
            }

            $timezone = (string) config('crm.timezone', 'UTC');
            $today = Carbon::now($timezone);

            // Fully past: two months ago, whole calendar month - guaranteed
            // to be entirely history, so this should hit report_daily_aggregates.
            $pastMonth = $today->copy()->subMonthsNoOverflow(2);
            $pastFrom = $pastMonth->copy()->startOfMonth()->toDateString();
            $pastTo = $pastMonth->copy()->endOfMonth()->toDateString();

            // Touches today: this calendar month to date - must fall back to
            // the live query (periodAggregate() refuses any range touching today).
            $currentFrom = $today->copy()->startOfMonth()->toDateString();
            $currentTo = $today->toDateString();

            $apiHeaders = ['Authorization' => 'Bearer '.$apiToken];

            $this->results['dashboard']['api_past_period'] = array_merge(
                $this->timeRequests($client, '/api/v1/reports/summary?from='.$pastFrom.'&to='.$pastTo, $repeats, $apiHeaders),
                ['from' => $pastFrom, 'to' => $pastTo, 'expected_path' => 'pre-aggregated (report_daily_aggregates)'],
            );

            $this->results['dashboard']['api_current_period'] = array_merge(
                $this->timeRequests($client, '/api/v1/reports/summary?from='.$currentFrom.'&to='.$currentTo, $repeats, $apiHeaders),
                ['from' => $currentFrom, 'to' => $currentTo, 'expected_path' => 'live query (touches today)'],
            );

            if ($csrfToken !== null) {
                $this->results['dashboard']['web_dashboard'] = array_merge(
                    $this->timeRequests($client, '/dashboard', $repeats, []),
                    ['expected_path' => 'live query (always current month)'],
                );
            }
        } finally {
            $server->stop(5);
        }
    }

    private function waitForServer(int $port): void
    {
        $deadline = microtime(true) + 15;
        $client = new Client(['timeout' => 1]);

        while (microtime(true) < $deadline) {
            try {
                $client->get('http://127.0.0.1:'.$port.'/login');

                return;
            } catch (Throwable) {
                usleep(200_000);
            }
        }

        throw new RuntimeException('php artisan serve did not come up on port '.$port.' within 15s.');
    }

    /** @return array<string, mixed> */
    private function timeRequests(Client $client, string $uri, int $repeats, array $headers): array
    {
        $timings = [];
        $errors = 0;

        for ($i = 0; $i < $repeats; $i++) {
            $start = microtime(true);
            $response = $client->get($uri, ['headers' => $headers]);
            $elapsedMs = (microtime(true) - $start) * 1000;

            if ($response->getStatusCode() >= 400) {
                $errors++;

                continue;
            }

            $timings[] = $elapsedMs;
        }

        sort($timings);
        $count = count($timings);

        $percentile = function (float $p) use ($timings, $count) {
            if ($count === 0) {
                return null;
            }

            $index = (int) min($count - 1, floor($p * $count));

            return round($timings[$index], 1);
        };

        return [
            'requests' => $repeats,
            'errors' => $errors,
            'min_ms' => $count ? round($timings[0], 1) : null,
            'avg_ms' => $count ? round(array_sum($timings) / $count, 1) : null,
            'p95_ms' => $percentile(0.95),
            'max_ms' => $count ? round($timings[$count - 1], 1) : null,
        ];
    }

    // -------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------

    /**
     * Drains the `messages` queue in-process by calling `Illuminate\Queue\
     * Worker` directly - the exact class, job-reservation SQL and retry/
     * backoff logic `queue:work` itself calls - rather than spawning a second
     * `queue:work` OS process. See this class's own docblock for why: an
     * earlier version of this harness spawned a real subprocess, saw
     * intermittent "table doesn't exist" errors, and wrongly attributed them
     * to MariaDB instability. The real cause was a second, concurrent
     * `crm:load-test` run sharing the same disposable database - now made
     * impossible by `withLoadTestLock()` in `handle()` - not anything wrong
     * with a subprocess worker on this machine. A real `queue:work` subprocess
     * was independently re-verified clean once that lock existed
     * (docs/LOAD_TEST_RESULTS.md §5).
     *
     * The retry/purge below is kept anyway: it is the same shape a real
     * worker supervisor (systemd, Supervisor) provides in production - a
     * crashed worker restarts and keeps draining - and costs nothing when, as
     * expected under the lock, it never triggers. `transientQueueErrors` is
     * still counted and reported so a future recurrence is visible rather
     * than silently absorbed.
     *
     * @return int jobs processed (0 once the queue is empty)
     */
    private function drainQueueInProcess(?int $maxJobs): int
    {
        // Not app(Worker::class) - its constructor takes a raw callable
        // ($isDownForMaintenance) the container cannot autowire. 'queue.worker'
        // is the singleton QueueServiceProvider itself builds with that
        // callable supplied, so it is already correctly constructed.
        $worker = app('queue.worker');
        $options = new WorkerOptions(sleep: 0, maxTries: 3);

        $processed = 0;
        $consecutiveErrors = 0;

        while ($maxJobs === null || $processed < $maxJobs) {
            try {
                $job = app('queue')->connection('database')->pop('messages');

                if ($job === null) {
                    break;
                }

                $worker->process('database', $job, $options);
                $processed++;
                $consecutiveErrors = 0;
            } catch (Throwable $e) {
                $consecutiveErrors++;
                $this->transientQueueErrors++;

                // Ten in a row is not a transient blip any more - something is
                // genuinely broken and the run should fail loudly rather than
                // spin.
                if ($consecutiveErrors > 10) {
                    throw $e;
                }

                usleep(200_000);
                DB::purge('mysql');
            }
        }

        return $processed;
    }

    /**
     * The next auto-increment value for `jobs`. InnoDB never reuses a
     * consumed auto-increment value even after the row is deleted, so the
     * delta of this across a queue:work run is the true count of job rows
     * that ever existed during it - including ones enqueued AND fully
     * processed within the same run, which a simple row count would miss.
     */
    private function jobsTableNextId(): int
    {
        $row = DB::select("SHOW TABLE STATUS LIKE 'jobs'");

        return (int) ($row[0]->Auto_increment ?? 0);
    }

    private function truncateFor(string $scenario): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $common = ['jobs', 'failed_jobs'];

        $tables = $scenario === 'campaign'
            ? array_merge($common, ['campaign_recipients', 'campaigns', 'messages', 'lead_status_history', 'leads'])
            : array_merge($common, [
                'payment_status_history', 'payments', 'sales', 'quotation_items', 'quotations',
                'opportunity_products', 'opportunities', 'customer_leads', 'customers',
                'messages', 'calls', 'lead_status_history', 'report_daily_aggregates', 'leads',
            ]);

        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function syntheticPhone(int $index, string $prefix): string
    {
        return $prefix.str_pad((string) ($index % 100_000_000), 8, '0', STR_PAD_LEFT);
    }

    private function rate(int $count, float $seconds): float
    {
        return $seconds > 0 ? round($count / $seconds, 1) : 0.0;
    }

    // -------------------------------------------------------------------
    // Reporting
    // -------------------------------------------------------------------

    private function printSummary(): void
    {
        if (isset($this->results['campaign'])) {
            $c = $this->results['campaign'];
            $this->components->info('Campaign throughput results (FR-CAMP-05)');
            $this->table(['Metric', 'Value'], [
                ['Leads seeded', number_format($c['leads_seeded']).' in '.$c['seed_seconds'].'s ('.$c['seed_rate_per_sec'].' rows/s)'],
                ['Audience targeted', number_format($c['audience_targeted'])],
                ['Audience build time', $c['audience_build_seconds'].'s ('.$c['audience_build_rate_per_sec'].' leads/s)'],
                ['Fan-out time (DispatchCampaign)', $c['fanout_seconds'].'s for '.number_format($c['fanout_enqueued']).' jobs ('.$c['fanout_rate_per_sec'].' jobs/s)'],
                ['Drain time (queue:work --stop-when-empty)', $c['drain_seconds'].'s'],
                ['Recipients processed', number_format($c['recipients_processed']).' (sent '.$c['recipients_sent'].', skipped '.$c['recipients_skipped'].', failed '.$c['recipients_failed'].')'],
                ['Recipient throughput', $c['recipient_rate_per_sec'].' recipients/s'],
                ['Raw queue jobs churned during drain', number_format($c['raw_jobs_churned']).' ('.$c['raw_job_rate_per_sec'].' jobs/s)'],
                ['failed_jobs rows left behind', $c['failed_jobs_table_count']],
                ['Transient DB errors absorbed mid-drain', $c['transient_queue_errors']],
            ]);

            if ($c['recipient_rate_per_sec'] > 0) {
                $extrapolated = round(50000 / $c['recipient_rate_per_sec'], 1);
                $this->components->info(sprintf(
                    'Linear extrapolation to a 50,000-lead campaign at the measured recipient rate: ~%s seconds (~%s minutes) of single-worker drain time. See docs/LOAD_TEST_RESULTS.md for why this is a floor, not a promise.',
                    number_format($extrapolated),
                    number_format($extrapolated / 60, 1),
                ));
            }
        }

        if (isset($this->results['dashboard'])) {
            $d = $this->results['dashboard'];

            if (isset($d['seed'])) {
                $this->components->info('Dashboard seed volume');
                $this->table(['Table', 'Rows'], [
                    ['Days seeded', $d['seed']['days']],
                    ['Leads', number_format($d['seed']['leads_total'])],
                    ['Calls', number_format($d['seed']['calls_total'])],
                    ['Messages', number_format($d['seed']['messages_total'])],
                    ['Sales', number_format($d['seed']['sales_total'])],
                    ['Payments', number_format($d['seed']['payments_total'])],
                    ['Seed time', $d['seed']['seed_seconds'].'s'],
                    ['Aggregation time (crm:aggregate-daily-reports)', ($d['aggregation_seconds'] ?? '?').'s'],
                ]);
            }

            $this->components->info('Dashboard response results (FR-RPT-05)');

            foreach ([
                'api_past_period' => 'GET /api/v1/reports/summary - fully-past period',
                'api_current_period' => 'GET /api/v1/reports/summary - touches today',
                'web_dashboard' => 'GET /dashboard - web (always current month)',
            ] as $key => $label) {
                $stats = $d[$key] ?? null;

                if ($stats === null) {
                    $this->components->warn($label.': not measured');

                    continue;
                }

                $this->table(['Metric', 'Value'], [
                    ['Endpoint', $label],
                    ['Expected path', $stats['expected_path']],
                    ['Requests', $stats['requests'].' ('.$stats['errors'].' errors)'],
                    ['Min', $stats['min_ms'].' ms'],
                    ['Avg', $stats['avg_ms'].' ms'],
                    ['P95', $stats['p95_ms'].' ms'],
                    ['Max', $stats['max_ms'].' ms'],
                ]);
            }
        }

        $path = storage_path('app/loadtest-results.json');
        file_put_contents($path, json_encode($this->results, JSON_PRETTY_PRINT));
        $this->components->info('Raw results written to '.$path);
    }
}
