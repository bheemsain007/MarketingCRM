<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Proof that the scheduler cron is alive (DEPLOYMENT §5, §9).
 *
 * The failure this exists for is silent, which is why it is worth a command of
 * its own. A missing or mistyped cron line does not error: `schedule:run` is
 * simply never invoked, so follow-up reminders never fire (FR-FUP-03), overdue
 * payments are never marked (BR-PAY-06), and two retention purges - lead import
 * files and call recordings, both bulk PII - quietly stop running (SEC-PII-05).
 * Nothing appears in any log, because nothing ran. The first symptom is a
 * compliance question nobody can answer months later.
 *
 * A heartbeat inverts that: absence becomes the signal. `crm:production-check`
 * reads it, GET /api/v1/health/scheduler exposes it to an uptime monitor, and
 * both fail loudly once it goes stale.
 *
 * **The cache store, not the database.** On the Hostinger target the cache store
 * IS the database (DEPLOYMENT §3A), so this is a durable row either way - but
 * writing it through the cache means the check keeps working unchanged on a VPS
 * with Redis, and it costs one key rather than a migration for a single value.
 * The TTL is deliberately longer than the staleness threshold, so an expired
 * key and a never-written key are the same answer: "not running".
 */
class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'crm:scheduler-heartbeat
                            {--check : Report whether the last beat is recent, and fail if not}';

    protected $description = 'Record (or check) proof that the scheduler cron is running';

    public const CACHE_KEY = 'crm.scheduler.heartbeat';

    /**
     * Minutes before a heartbeat is considered stale.
     *
     * Five, matching the alert threshold in DEPLOYMENT §9. The scheduler ticks
     * every minute, so this tolerates four consecutive missed runs - enough to
     * ride out a slow deploy or a host hiccup without paging anybody, short
     * enough that a dead cron is noticed the same morning.
     */
    public const STALE_AFTER_MINUTES = 5;

    public function handle(): int
    {
        if ($this->option('check')) {
            return $this->report();
        }

        Cache::put(
            self::CACHE_KEY,
            now()->toIso8601String(),
            now()->addMinutes(self::STALE_AFTER_MINUTES * 12),
        );

        return self::SUCCESS;
    }

    /** The last recorded beat, or null if the scheduler has never run here. */
    public static function lastBeat(): ?Carbon
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_string($value) ? Carbon::parse($value) : null;
    }

    public static function isHealthy(): bool
    {
        $last = self::lastBeat();

        return $last !== null && $last->greaterThan(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    private function report(): int
    {
        $last = self::lastBeat();

        if ($last === null) {
            $this->error('No scheduler heartbeat has ever been recorded. The cron entry is missing (DEPLOYMENT §5).');

            return self::FAILURE;
        }

        if (! self::isHealthy()) {
            $this->error(sprintf(
                'Scheduler heartbeat is stale: last run %s (%d minute(s) ago, threshold %d).',
                $last->toDateTimeString(),
                $last->diffInMinutes(now()),
                self::STALE_AFTER_MINUTES,
            ));

            return self::FAILURE;
        }

        $this->info('Scheduler heartbeat is healthy; last run '.$last->toDateTimeString().'.');

        return self::SUCCESS;
    }
}
