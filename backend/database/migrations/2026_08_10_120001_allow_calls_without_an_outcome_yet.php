<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `calls.status` becomes nullable (Phase 9, FR-CALL-03, ADR-B).
 *
 * Under ADR-B the Web CRM creates the call record and the dial intent, and the
 * DEVICE reports what happened afterwards. Between those two moments a call
 * genuinely has no outcome - it is ringing.
 *
 * The original column was NOT NULL, which forced a choice between inventing a
 * twelfth status (FR-CALL-01 says only the eleven are accepted) or writing a
 * fake outcome like `no_response` at dial time and hoping it gets corrected.
 * The second is worse than it looks: a dropped device report would leave a
 * permanent record saying the lead did not answer, when nobody knows whether
 * they did.
 *
 * So: `status = null` means "dialled, outcome not yet reported". FR-CALL-01 is
 * untouched - the eleven remain the only *outcomes* accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('status', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Any call still awaiting its outcome has to be given one before the
        // column can be NOT NULL again. `no_response` is the honest choice:
        // we dialled and never learned the result.
        DB::table('calls')->whereNull('status')->update(['status' => 'no_response']);

        Schema::table('calls', function (Blueprint $table) {
            $table->string('status', 30)->nullable(false)->change();
        });
    }
};
