<?php

namespace App\Jobs;

use App\Models\LeadExport;
use App\Services\Leads\LeadExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one queued lead CSV export off the request thread (FR-LEAD-12, NFR-06).
 *
 * Carries the export ID rather than the model, like ProcessLeadImport: a
 * queued payload holding a serialised model is a snapshot, and this job must
 * act on the record's CURRENT state - including re-checking, when it
 * actually runs, that the requester still holds `leads.export`.
 *
 * Runs on the `reports` queue (ARCH §4, "Report aggregation/pre-computation,
 * Low, Off-peak") - a bulk read-and-summarise job, not the row-write traffic
 * `imports` is documented for.
 */
class LeadExportJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt only, like the import job: a genuine failure is better
     * surfaced to the operator than retried silently against a query that may
     * fail the same way again.
     */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $exportId,
    ) {
        $this->onQueue('reports');
    }

    public function handle(LeadExportService $exports): void
    {
        $export = LeadExport::find($this->exportId);

        if ($export === null) {
            return;     // Deleted before the worker picked it up.
        }

        $exports->process($export);
    }

    /**
     * Without this the export would sit at `processing` forever and the
     * operator would have no way to tell a slow export from a dead one.
     */
    public function failed(Throwable $e): void
    {
        $export = LeadExport::find($this->exportId);

        if ($export === null || $export->isFinished()) {
            return;
        }

        app(LeadExportService::class)->markFailed($export, $e->getMessage());
    }
}
