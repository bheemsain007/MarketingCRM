<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call recordings (DATABASE_SCHEMA §2.6, FR-REC-01..05).
 *
 * `storage_path` always points at a PRIVATE disk - recordings are served only
 * through short-lived signed URLs (SEC-FILE-02/03). `expires_at` drives the
 * retention purge (BR-REC-02); a null value means "retain per policy default",
 * never "retain forever".
 *
 * `checksum` makes the Android upload queue idempotent: a retried upload of the
 * same file is recognised rather than duplicated (FR-REC-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('storage_disk', 30)->default('recordings');
            $table->string('storage_path', 255)->nullable();

            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('checksum', 64)->nullable();

            // pending | uploading | uploaded | failed | unavailable
            // `unavailable` = device/OS blocked recording; NOT an error state
            // (BR-REC-03).
            $table->string('upload_status', 20)->default('pending');
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedTinyInteger('upload_attempts')->default(0);

            $table->timestamp('device_captured_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique('call_id');
            $table->index('upload_status');
            // Retention purge job scans this.
            $table->index('expires_at');
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};
