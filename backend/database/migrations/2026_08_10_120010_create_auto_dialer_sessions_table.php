<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A telecaller's dialling run (FR-CALL-06).
 *
 * The session exists so that "pause halts dialling without losing queue
 * position" is a property of the database rather than of a browser tab. State
 * survives a page reload, a closed laptop and a shift change, because the
 * queue is a table and not a variable in someone's session storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_dialer_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // running | paused | stopped | completed
            $table->string('state', 20)->default('running');

            // What built the queue, kept so a run can be explained afterwards -
            // "why was this lead in my list?" is a real question.
            $table->json('filters')->nullable();

            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('dialled_items')->default(0);
            $table->unsignedInteger('skipped_items')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 30)->nullable();

            $table->timestamps();

            // "Does this telecaller already have a run open?" - the only query
            // this table serves on the hot path.
            $table->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_dialer_sessions');
    }
};
