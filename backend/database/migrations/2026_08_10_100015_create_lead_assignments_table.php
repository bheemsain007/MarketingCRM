<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment history (FR-LEAD-08, BR-ASSIGN-03).
 *
 * `leads.assigned_to` holds the current owner for query speed; THIS table is
 * authoritative and is the source for conversion attribution (GLOSSARY §2.6) -
 * which is why it records who owned the lead at any point in time, not just now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            // Null when assigned automatically by the system.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            // manual | round_robin | load_balanced | campaign_rule (BR-ASSIGN-01)
            $table->string('assignment_method', 20)->default('manual');

            $table->timestamp('assigned_at')->useCurrent();
            // Null = currently active assignment.
            $table->timestamp('unassigned_at')->nullable();
            $table->string('reason', 255)->nullable();

            $table->timestamps();

            $table->index(['lead_id', 'assigned_at']);
            $table->index(['assigned_to', 'assigned_at']);
            $table->index('unassigned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assignments');
    }
};
