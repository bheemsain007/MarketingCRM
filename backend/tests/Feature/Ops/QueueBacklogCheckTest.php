<?php

namespace Tests\Feature\Ops;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The pre-production check must notice work nobody is draining (ARCHITECTURE §4).
 *
 * This exists because of a real, shipped defect: the documented worker cron ran
 * `queue:work` with no `--queue`, so it drained `default` while every job in the
 * application names a queue. Nothing failed - the API kept answering "queued",
 * `failed_jobs` stayed empty, and password-reset mail (the one thing on
 * `default`) kept arriving, so a smoke test passed. The only evidence was rows
 * getting old in the `jobs` table, which is what these tests assert on.
 */
class QueueBacklogCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The check compares job age against a threshold, so the clock has to
        // hold still or the assertions drift with the wall.
        Carbon::setTestNow(Carbon::parse('2026-08-27 11:00:00'));

        config(['queue.default' => 'database']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  int  $ageMinutes  how long ago the job was queued
     */
    private function queueJob(string $queue, int $ageMinutes): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            // The jobs table stores a unix timestamp here, not a datetime.
            'created_at' => now()->subMinutes($ageMinutes)->getTimestamp(),
        ]);
    }

    #[Test]
    public function work_left_unclaimed_past_the_threshold_fails_the_check(): void
    {
        config(['crm.queue_backlog_alert_minutes' => 30]);

        $this->queueJob('messages', ageMinutes: 90);

        $this->artisan('crm:production-check')
            ->expectsOutputToContain('Queue "messages"')
            ->assertFailed();
    }

    #[Test]
    public function the_failure_names_the_queue_so_the_worker_can_be_fixed(): void
    {
        config(['crm.queue_backlog_alert_minutes' => 30]);

        $this->queueJob('reports', ageMinutes: 120);

        // Naming the queue is the whole point: the fix is adding that name to
        // the worker's --queue list, and a message that only said "backlog"
        // would not tell anyone which name to add.
        $this->artisan('crm:production-check')
            ->expectsOutputToContain('reports')
            ->assertFailed();
    }

    #[Test]
    public function a_queue_being_drained_normally_does_not_fail_the_check(): void
    {
        config(['crm.queue_backlog_alert_minutes' => 30]);

        // Freshly queued: a worker has simply not got to it in the last minute,
        // which is what a working system looks like under load.
        $this->queueJob('messages', ageMinutes: 2);

        $this->artisan('crm:production-check')
            ->doesntExpectOutputToContain('no worker is draining')
            ->run();
    }

    #[Test]
    public function an_empty_jobs_table_raises_nothing(): void
    {
        $this->artisan('crm:production-check')
            ->doesntExpectOutputToContain('no worker is draining')
            ->run();
    }

    #[Test]
    public function every_queue_the_code_dispatches_onto_is_declared_in_config(): void
    {
        // The guard against the next version of this bug. `crm.queues` is what
        // DEPLOYMENT §4's worker command is written from, so a job dispatched
        // onto a queue missing from that list is a job nobody will staff.
        $declared = config('crm.queues');

        $dispatched = [];

        foreach (['app/Jobs', 'app/Services', 'app/Http/Controllers'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    "/onQueue\(\s*'([a-z_]+)'\s*\)/",
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                );

                foreach ($matches[1] as $queue) {
                    $dispatched[$queue] = true;
                }
            }
        }

        $undeclared = array_diff(array_keys($dispatched), $declared);

        $this->assertSame([], array_values($undeclared), implode("\n", array_merge(
            ['These queues are dispatched onto but are not in config("crm.queues"):'],
            array_values($undeclared),
            ['A queue no worker is told to drain is work that never runs, silently.'],
        )));
    }
}
