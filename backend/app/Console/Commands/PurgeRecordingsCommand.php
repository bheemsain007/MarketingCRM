<?php

namespace App\Console\Commands;

use App\Models\CallRecording;
use App\Services\Calls\CallRecordingService;
use Illuminate\Console\Command;

/**
 * Deletes call recordings past their retention (BR-REC-02, FR-REC-05,
 * SEC-PII-05).
 *
 * Scheduler-owned, like the other time-derived sweeps: retention is the passage
 * of time, so nothing triggers it on its own, and without this job "we keep
 * recordings for a year" is a sentence in a policy document rather than a thing
 * the system does.
 *
 * The audio is deleted; the ROW survives with its path nulled and its status set
 * to `purged`, and each deletion writes an audit entry. Destroying the row would
 * destroy the evidence that the retention policy was honoured - which is the
 * only reason anyone asks.
 */
class PurgeRecordingsCommand extends Command
{
    protected $signature = 'crm:purge-recordings {--dry-run : Report without deleting}';

    protected $description = 'Delete call recordings whose retention period has expired';

    public function handle(CallRecordingService $recordings): int
    {
        if ($this->option('dry-run')) {
            $due = CallRecording::expired()->whereNotNull('storage_path')->count();

            $this->info(sprintf('[dry run] %d recording(s) would be purged.', $due));

            return self::SUCCESS;
        }

        $purged = $recordings->purgeExpired();

        $this->info(sprintf('%d recording(s) purged.', $purged));

        return self::SUCCESS;
    }
}
