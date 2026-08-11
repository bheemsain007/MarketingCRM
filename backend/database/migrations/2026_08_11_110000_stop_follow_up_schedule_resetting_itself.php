<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes `ON UPDATE CURRENT_TIMESTAMP` from `follow_ups.scheduled_at`.
 *
 * **This was silently destroying every follow-up schedule.**
 *
 * MySQL and MariaDB apply automatic initialisation AND automatic update to the
 * FIRST `TIMESTAMP NOT NULL` column in a table when it is declared without an
 * explicit default. Phase 2 wrote `$table->timestamp('scheduled_at')` bare, so
 * the column came out as:
 *
 *     scheduled_at TIMESTAMP NOT NULL
 *         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 *
 * Every write to the row - completing, cancelling, rescheduling, transferring
 * on reassignment, or the scheduler flagging a miss - therefore reset the
 * scheduled time to the moment of the update. A follow-up booked for Friday
 * became "due now" the first time anything touched it, and the missed-follow-up
 * report (BR-FUP-02) would have been measuring nothing but its own last write.
 *
 * Caught by the BR-FUP-03 test asserting that rescheduling preserves the
 * original's time - which it could not, through no fault of the service.
 *
 * The `DEFAULT CURRENT_TIMESTAMP` half is kept: it is harmless, since every
 * insert supplies a real value, and dropping it on MySQL requires a sentinel
 * default that would be a worse lie than the one it replaces.
 *
 * No other table is affected - the rest declared `useCurrent()`, which sets a
 * default and therefore suppresses the automatic-update half.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('follow_ups') || ! $this->onMySql()) {
            return;
        }

        DB::statement(
            'ALTER TABLE `follow_ups` MODIFY `scheduled_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );
    }

    /**
     * Deliberately not reinstated.
     *
     * A down() that restores data corruption is not a rollback, it is a second
     * outage. Rolling this migration back leaves the column in its fixed state.
     */
    public function down(): void
    {
        // Intentionally empty - see above.
    }

    private function onMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
