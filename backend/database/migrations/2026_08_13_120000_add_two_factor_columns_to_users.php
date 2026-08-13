<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication columns (SEC-AUTH-07, T-09).
 *
 * Every column is nullable and nothing is backfilled, because 2FA is OPTIONAL:
 * SEC-AUTH-07 says "recommended", and a migration that switched it on for
 * existing administrators would lock every one of them out on deploy day with
 * no enrolled device to let them back in.
 *
 * `two_factor_secret` and `two_factor_recovery_codes` are TEXT rather than
 * sized columns because both are stored encrypted with APP_KEY (SEC-PII-02),
 * and Laravel's envelope is several times the length of the plaintext.
 *
 * `two_factor_confirmed_at` - not a boolean - is what distinguishes "started
 * enrolling" from "proved they can generate a code". Only the second state
 * challenges at login; anything else would let a half-finished enrolment lock
 * someone out of their own account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            /*
             * The last time step a code was accepted for - the anti-replay
             * record (RFC 6238 §5.2). A TOTP code stays mathematically valid
             * for its whole window, so without this column a code shoulder-
             * surfed or read from a proxy log can be presented a second time
             * and works. Storing the step and refusing anything at or below it
             * makes each code single-use, which is the property people assume
             * TOTP already has and which nothing enforces for free.
             */
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable()
                ->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_timestep',
            ]);
        });
    }
};
