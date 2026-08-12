<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The password reset email, sent off the request thread (SEC-AUTH-02).
 *
 * Laravel's shipped notification is synchronous, which made the reset form a
 * timing oracle: only an address matching an active account reached the send,
 * so that branch cost a mail round-trip the "no such user" branch never paid,
 * and an attacker could tell the two apart with a stopwatch. Queueing removes
 * the difference from the response entirely.
 *
 * It also removes a real availability problem: a slow or unreachable SMTP host
 * would otherwise hold the web request open for its whole timeout.
 *
 * Nothing else changes - the URL still comes from `ResetPassword::createUrlUsing`
 * in AppServiceProvider, and the token is still generated in-request, so the
 * link is valid the moment it is queued.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
