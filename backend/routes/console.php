<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| Requires the scheduler cron entry from DEPLOYMENT §6. Without it nothing
| below ever runs, which for a retention job is a silent compliance failure
| rather than a visible bug - so it is listed in the deployment checklist.
*/

/*
 * Proof of life for the cron entry itself (DEPLOYMENT §5, §9).
 *
 * Listed FIRST because every other line below depends on it being true. A
 * missing cron entry is silent: nothing errors, work simply never happens, and
 * three retention purges stop running with no signal anywhere (SEC-PII-05). This
 * writes a timestamp every minute so that absence becomes the alarm -
 * `crm:production-check` and GET /api/v1/health/scheduler both read it.
 *
 * NOT `withoutOverlapping`: the whole point is that it runs, and an overlap
 * lock that got stuck would suppress exactly the signal being monitored. The
 * write is a single cache key, so a genuine overlap is harmless.
 */
Schedule::command('crm:scheduler-heartbeat')->everyMinute();

// Retention on uploaded lead files (SEC-PII-05). Runs off-peak; deleting a
// few files is cheap, but it reads a table the import path also writes.
Schedule::command('leads:purge-import-files')
    ->dailyAt('03:30')
    ->withoutOverlapping();

/*
 * Retention on GENERATED export files (FR-LEAD-12, SEC-PII-04/05). The mirror
 * of the line above: an export is the same bulk lead PII travelling the other
 * way, and `expires_at` only stops the download - it deletes nothing. Daily,
 * because expiry can only change at a day boundary, and alongside its sibling
 * because both are cheap file deletions in the quiet hours.
 */
Schedule::command('leads:purge-export-files')
    ->dailyAt('03:30')
    ->withoutOverlapping();

/*
 * Follow-up reminders and missed detection (FR-FUP-03, BR-FUP-02, BR-NOTIF-04).
 *
 * Every minute, because a reminder is only useful on time - an hourly tick
 * would deliver a 15-minute warning up to 45 minutes early or 15 late.
 * `withoutOverlapping` matters more here than on the daily job: after downtime
 * this can face a large backlog, and two overlapping runs would notify twice.
 */
Schedule::command('crm:process-follow-ups')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Scheduled campaigns (FR-CAMP-02, BR-CAMP-03/05).
 *
 * Without this line a campaign scheduled for 09:00 stays `scheduled` for ever
 * while every screen claims it is going out - a silent failure that looks like
 * success. Per-minute because a send window is chosen for a reason: a campaign
 * scheduled for 09:00 and started at 09:59 has already missed it.
 *
 * `withoutOverlapping` is load-bearing rather than tidy. After downtime this
 * faces a backlog of due campaigns, each of which materialises an audience of
 * unbounded size, and two overlapping runs could hand the same campaign to the
 * queue twice - which is a second copy of the message at the recipient's end.
 */
Schedule::command('crm:dispatch-scheduled-campaigns')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Overdue payments (BR-PAY-06). Daily rather than per-minute: "overdue" is a
 * date comparison, so it can only change at a day boundary. Runs early enough
 * that the collections list is right before anyone looks at it.
 */
Schedule::command('crm:mark-overdue-payments')
    ->dailyAt('06:00')
    ->withoutOverlapping();

/*
 * Score decay and temperature (BR-SCORE-01, BR-TEMP-02). Decay is the absence
 * of events, so nothing triggers it on its own - without this sweep a lead
 * scored 85 six months ago still reads Hot, and the Hot view fills with people
 * who stopped answering in March. Off-peak; it rewrites a row per active lead.
 */
Schedule::command('crm:decay-lead-scores')
    ->dailyAt('04:15')
    ->withoutOverlapping();

/*
 * Abandoned work sessions (FR-ATT-01, Phase 26, T-24).
 *
 * Without this line a telecaller who closes the browser leaves a session open
 * for ever, and an open session counts its logged-in time up to now() - so the
 * number that feeds telecaller reports, and therefore pay, grows on its own
 * while nobody is at the desk. Nothing else triggers this: the absence of a
 * logout is not an event anything can listen for.
 *
 * Every fifteen minutes, not daily. The sweep is what bounds the error, and a
 * daily job would leave an abandoned session open for up to a day past the
 * threshold. It is cheap - one indexed scan of the open sessions - and it is
 * idempotent, since a closed session is no longer open.
 */
Schedule::command('crm:close-stale-work-sessions')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/*
 * Recording retention (BR-REC-02, FR-REC-05, SEC-PII-05). Retention is the
 * passage of time, so nothing triggers it on its own - without this sweep the
 * stated retention period is a sentence in a policy document rather than
 * something the system does. Off-peak and daily: expiry can only change at a
 * day boundary, and each run deletes files from disk.
 */
Schedule::command('crm:purge-recordings')
    ->dailyAt('03:30')
    ->withoutOverlapping();

/*
 * Report pre-aggregation (FR-RPT-05). Dashboards read `report_daily_aggregates`
 * instead of scanning calls/messages/lead_status_history directly for any
 * period that is fully-past history - without this line those rows are never
 * written and BusinessReportService::summary()/revenue() fall back to the live
 * query on every request, forever.
 *
 * Early morning, well after the ORG_TIMEZONE day it aggregates has ended
 * (IST is UTC+5:30, so even midnight UTC is already well into the next IST
 * day). `withoutOverlapping` because two concurrent runs would upsert the same
 * rows twice for no benefit and hold the aggregates table longer than needed.
 */
Schedule::command('crm:aggregate-daily-reports')
    ->dailyAt('04:45')
    ->withoutOverlapping();

/*
 * Database backup (SEC-OPS-05, DEPLOYMENT §8). Early morning and off any other
 * sweep's minute so a slow mysqldump on a large database does not compete with
 * the report aggregation run above for I/O. `withoutOverlapping` because a
 * second dump starting before the first finishes writing is exactly the
 * "backup taken mid-write" scenario --single-transaction exists to avoid.
 */
Schedule::command('crm:backup')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('crm:purge-backups')
    ->dailyAt('02:30')
    ->withoutOverlapping();
