<?php

namespace App\Jobs;

use App\Models\LeadImport;
use App\Services\Leads\LeadImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads an uploaded file and fans it out into per-row jobs (FR-LEAD-07).
 *
 * Carries the import ID rather than the model: a queued payload holding a
 * serialised model is a snapshot, and this job's whole purpose is to act on
 * the record's CURRENT state.
 *
 * Runs on the `imports` queue - low priority, long running, deliberately kept
 * away from `dialer` and `critical` (ARCH §4).
 */
class ProcessLeadImport implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt only. Re-reading the file would re-dispatch every row job;
     * those are idempotent, so it would not duplicate leads, but it would
     * double the queue load for no gain. A genuine failure is better surfaced
     * to the operator than retried silently.
     */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $importId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(LeadImportService $imports): void
    {
        $import = LeadImport::find($this->importId);

        if ($import === null) {
            return;     // Deleted before the worker picked it up.
        }

        $imports->dispatchRows($import);
    }

    /**
     * Without this the import would sit at `processing` forever and the
     * operator would have no way to tell a slow import from a dead one.
     */
    public function failed(Throwable $e): void
    {
        $import = LeadImport::find($this->importId);

        if ($import === null || $import->isFinished()) {
            return;
        }

        app(LeadImportService::class)->markFailed($import, $e->getMessage());
    }
}
