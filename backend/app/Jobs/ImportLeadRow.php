<?php

namespace App\Jobs;

use App\Enums\ImportRowStatus;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Services\Leads\LeadImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imports one row (FR-LEAD-07).
 *
 * One job per row is what makes the acceptance criterion "never partially
 * corrupts on failure" true: a row is retried, reported or abandoned entirely
 * on its own, and cannot take the other 49,999 with it.
 *
 * The raw row travels in the payload rather than being re-read from the file,
 * so a row job does not need the file to still exist and does not re-parse a
 * large CSV once per row.
 */
class ImportLeadRow implements ShouldQueue
{
    use Queueable;

    /** Transient failures (a dropped DB connection) deserve a retry; bad data does not, and never reaches here as an exception. */
    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [5, 15];

    /**
     * @param  array<string, string>  $row  raw header => value
     */
    public function __construct(
        public readonly int $importId,
        public readonly int $rowNumber,
        public readonly array $row,
    ) {
        $this->onQueue('imports');
    }

    public function handle(LeadImportService $imports): void
    {
        $import = LeadImport::find($this->importId);

        if ($import === null || $import->isFinished()) {
            return;
        }

        $imports->importRow($import, $this->rowNumber, $this->row);
    }

    /**
     * Records the row as failed after the last retry.
     *
     * This is not optional bookkeeping: the import finishes when
     * processed_rows reaches total_rows, so a row that gives up without
     * counting itself would leave the whole import stuck at `processing`.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Lead import row job failed permanently.', [
            'lead_import_id' => $this->importId,
            'row_number' => $this->rowNumber,
            'exception' => $e->getMessage(),
        ]);

        try {
            $exists = LeadImportRow::where('lead_import_id', $this->importId)
                ->where('row_number', $this->rowNumber)
                ->exists();

            if ($exists) {
                return;     // Already counted before the failure.
            }

            DB::transaction(function () {
                LeadImportRow::create([
                    'lead_import_id' => $this->importId,
                    'row_number' => $this->rowNumber,
                    'status' => ImportRowStatus::Failed,
                    'message' => 'The row could not be processed after several attempts.',
                    'data' => $this->row,
                ]);

                LeadImport::whereKey($this->importId)->update([
                    'processed_rows' => DB::raw('processed_rows + 1'),
                    'invalid_rows' => DB::raw('invalid_rows + 1'),
                    'updated_at' => now(),
                ]);
            });

            $import = LeadImport::find($this->importId);

            if ($import !== null) {
                app(LeadImportService::class)->finaliseIfComplete($import);
            }
        } catch (Throwable $inner) {
            // Nothing further can be done from here; the import will show as
            // incomplete, which is the honest outcome.
            Log::error('Could not record a failed import row.', [
                'lead_import_id' => $this->importId,
                'row_number' => $this->rowNumber,
                'exception' => $inner->getMessage(),
            ]);
        }
    }
}
