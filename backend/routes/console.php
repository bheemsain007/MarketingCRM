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

// Retention on uploaded lead files (SEC-PII-05). Runs off-peak; deleting a
// few files is cheap, but it reads a table the import path also writes.
Schedule::command('leads:purge-import-files')
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
 * Recording retention (BR-REC-02, FR-REC-05, SEC-PII-05). Retention is the
 * passage of time, so nothing triggers it on its own - without this sweep the
 * stated retention period is a sentence in a policy document rather than
 * something the system does. Off-peak and daily: expiry can only change at a
 * day boundary, and each run deletes files from disk.
 */
Schedule::command('crm:purge-recordings')
    ->dailyAt('03:30')
    ->withoutOverlapping();
