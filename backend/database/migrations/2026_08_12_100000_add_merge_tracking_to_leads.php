<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merge tracking on leads (BR-DUP-04, T-64).
 *
 * A merged lead is soft-deleted rather than destroyed - the whole point of
 * BR-DUP-04 is that history survives - which leaves its phone number holding
 * the `unique(tenant_id, phone_e164)` index for ever. Without a pointer, a
 * later enquiry from that number resolves to a deleted record and stops there.
 *
 * `merged_into_id` makes the redirect explicit: the number still resolves, and
 * it resolves to the lead that now carries the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('merged_into_id')
                ->nullable()
                ->after('id')
                // Never cascade. Deleting a survivor must not silently destroy
                // the trail showing where a merged lead went.
                ->constrained('leads')
                ->nullOnDelete();

            $table->timestamp('merged_at')->nullable()->after('merged_into_id');

            $table->index('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['merged_into_id']);
            $table->dropIndex(['merged_into_id']);
            $table->dropColumn(['merged_into_id', 'merged_at']);
        });
    }
};
