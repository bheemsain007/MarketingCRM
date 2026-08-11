<?php

namespace App\Services\Leads;

use App\Enums\ErrorCode;
use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Exceptions\ApiException;
use App\Jobs\ImportLeadRow;
use App\Jobs\ProcessLeadImport;
use App\Models\LeadImport;
use App\Models\LeadImportRow;
use App\Models\User;
use App\Support\CsvReader;
use App\Support\LeadColumnMap;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bulk lead import (FR-LEAD-07).
 *
 * Three properties this is built to guarantee:
 *
 * 1. **Nothing bulk happens in an HTTP request.** The upload is validated and
 *    stored, then everything else is queued (NFR-06). A 50,000-row file must
 *    not depend on a browser staying open.
 *
 * 2. **A bad row never damages the import.** Each row is its own job and its
 *    own transaction, so one malformed number cannot roll back the 4,000 leads
 *    already written, and cannot fail the run (FR-LEAD-07: "never partially
 *    corrupts on failure").
 *
 * 3. **Rows go through LeadService like every other entry path.** Phone
 *    normalisation, duplicate detection and timeline writing are therefore
 *    identical whether a lead arrives by hand, by import or by webhook - which
 *    is the reason LeadService exists (BR-DUP-01/02).
 */
class LeadImportService
{
    public function __construct(
        private readonly LeadService $leads,
    ) {}

    // -----------------------------------------------------------------------
    // Intake
    // -----------------------------------------------------------------------

    /**
     * Validates the file's SHAPE, stores it and queues the run.
     *
     * Shape problems - no header, no phone column, an empty or oversized file -
     * are rejected synchronously with a 422, because the operator is still
     * sitting in front of the upload dialogue and can fix them. Row-level
     * problems are not: those are the report's job.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiException when the file cannot be used at all
     */
    public function createFromUpload(UploadedFile $file, User $actor, array $options = []): LeadImport
    {
        $disk = (string) config('crm.imports.disk');
        $directory = trim((string) config('crm.imports.directory'), '/');

        // Stored under a generated name: the original filename is attacker
        // controlled and is kept as data, never used as a path (SEC-FILE-01).
        $storedPath = $file->storeAs(
            $directory,
            Str::uuid()->toString().'.'.($file->getClientOriginalExtension() ?: 'csv'),
            ['disk' => $disk],
        );

        if ($storedPath === false) {
            throw new ApiException(ErrorCode::ServerError, 'The uploaded file could not be stored.');
        }

        try {
            $absolutePath = Storage::disk($disk)->path($storedPath);

            $reader = new CsvReader($absolutePath);
            $header = $reader->header();

            /** @var array<string, string> $overrides */
            $overrides = is_array($options['column_map'] ?? null) ? $options['column_map'] : [];
            $map = LeadColumnMap::resolve($header, $overrides);

            if ($missing = LeadColumnMap::missingRequired($map)) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'The file has no column for: '.implode(', ', $missing).'.',
                    errors: array_map(fn (string $field) => [
                        'field' => 'column_map.'.$field,
                        'code' => ErrorCode::ValidationFailed->value,
                        'message' => sprintf('No column matched "%s". Map it explicitly with column_map[%s].', $field, $field),
                    ], $missing),
                    // The detected header goes back so the client can render a
                    // mapping UI instead of making the user guess.
                    context: ['detected_header' => $header, 'detected_map' => $map],
                );
            }

            $totalRows = $reader->countDataRows();
            $maxRows = (int) config('crm.imports.max_rows');

            if ($totalRows === 0) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'The file contains a header but no data rows.',
                );
            }

            if ($totalRows > $maxRows) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    sprintf('The file has %s rows; the limit is %s. Split it and upload again.', number_format($totalRows), number_format($maxRows)),
                    context: ['total_rows' => $totalRows, 'max_rows' => $maxRows],
                );
            }
        } catch (ApiException $e) {
            // A file we will never process must not sit on disk holding PII.
            Storage::disk($disk)->delete($storedPath);

            throw $e;
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($storedPath);

            Log::warning('Lead import file could not be read.', [
                'user_id' => $actor->id,
                'filename' => $file->getClientOriginalName(),
                'exception' => $e->getMessage(),
            ]);

            throw new ApiException(
                ErrorCode::ValidationFailed,
                'The file could not be read as CSV. Save it as CSV (comma separated) and try again.',
            );
        }

        $import = LeadImport::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'original_filename' => $file->getClientOriginalName(),
            'disk' => $disk,
            'stored_path' => $storedPath,
            'file_size' => $file->getSize() ?: 0,
            'column_map' => $map,
            'options' => $this->sanitiseOptions($options),
            'uploaded_by' => $actor->id,
        ]);

        $import->forceFill(['total_rows' => $totalRows])->save();

        ProcessLeadImport::dispatch($import->id);

        return $import->refresh();
    }

    // -----------------------------------------------------------------------
    // Processing
    // -----------------------------------------------------------------------

    /**
     * Fans the file out into one job per row (ARCH §4, `imports` queue).
     *
     * Dispatched in chunks so the parent job never builds a single payload of
     * 50,000 jobs - that is what exhausts memory on shared hosting long before
     * the row work itself does.
     */
    public function dispatchRows(LeadImport $import): void
    {
        if ($import->isFinished()) {
            return;
        }

        if ($import->fileWasPurged()) {
            $this->markFailed($import, 'The uploaded file is no longer available.');

            return;
        }

        $import->forceFill([
            'status' => ImportStatus::Processing,
            'started_at' => now(),
        ])->save();

        try {
            $reader = new CsvReader(Storage::disk($import->disk)->path($import->stored_path));
            $chunkSize = max(1, (int) config('crm.imports.chunk_size'));

            $chunk = [];

            foreach ($reader->rows() as $rowNumber => $row) {
                $chunk[] = new ImportLeadRow($import->id, $rowNumber, $row);

                if (count($chunk) >= $chunkSize) {
                    $this->dispatchChunk($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->dispatchChunk($chunk);
            }
        } catch (Throwable $e) {
            $this->markFailed($import, $e->getMessage());

            return;
        }

        // A file whose rows were all blank still has to reach a terminal state;
        // with no row jobs to trigger it, nothing else ever would.
        $this->finaliseIfComplete($import->refresh());
    }

    /**
     * Turns one row into a lead, or into a reason why not.
     *
     * @param  array<string, string>  $row  raw header => value
     */
    public function importRow(LeadImport $import, int $rowNumber, array $row): void
    {
        // Idempotency: the unique index on (import, row) makes a retry a no-op
        // rather than a second lead or a double-counted result.
        if (LeadImportRow::where('lead_import_id', $import->id)->where('row_number', $rowNumber)->exists()) {
            return;
        }

        $values = LeadColumnMap::apply($row, $import->column_map ?? []);
        $options = $import->options ?? [];

        if ($failure = $this->validateRow($values)) {
            $this->recordRow($import, $rowNumber, $row, ImportRowStatus::Invalid, $failure);

            return;
        }

        $attributes = [
            'name' => $values['name'],
            'phone' => $values['phone'],
            'company' => $values['company'] ?? null,
            'email' => $values['email'] ?? null,
            'city' => $values['city'] ?? null,
            'state' => $values['state'] ?? null,
            'timezone' => $values['timezone'] ?? null,
            'priority' => isset($values['priority']) ? (int) $values['priority'] : 0,
            'lead_source_id' => $options['lead_source_id'] ?? null,
            'campaign_id' => $options['campaign_id'] ?? null,
        ];

        if (isset($values['country'])) {
            $attributes['country'] = $values['country'];
        }

        if (isset($values['alt_phone'])) {
            $attributes['alt_phone_e164'] = PhoneNumber::normalise($values['alt_phone']);
        }

        try {
            $lead = $this->leads->create(
                array_filter($attributes, fn ($value) => $value !== null),
                $import->uploaded_by,
                autoAssign: (bool) ($options['auto_assign'] ?? false),
            );
        } catch (ApiException $e) {
            // A duplicate is an expected outcome of a real import, not an
            // error: the lead already exists and already has an owner
            // (BR-DUP-02, BR-ASSIGN-05). It is reported, with a link to the
            // record that already holds the number, and the row moves on.
            $status = $e->errorCode === ErrorCode::LeadDuplicate
                ? ImportRowStatus::Duplicate
                : ImportRowStatus::Invalid;

            $existingLeadId = is_array($e->context) ? ($e->context['existing_lead_id'] ?? null) : null;

            $this->recordRow($import, $rowNumber, $row, $status, $e->getMessage(), $existingLeadId);

            return;
        } catch (Throwable $e) {
            // A row that broke on write is logged in full and reported plainly:
            // database internals never reach the client (NFR-08).
            Log::error('Lead import row failed.', [
                'lead_import_id' => $import->id,
                'row_number' => $rowNumber,
                'exception' => $e->getMessage(),
            ]);

            $this->recordRow($import, $rowNumber, $row, ImportRowStatus::Failed, 'The row could not be saved.');

            return;
        }

        // Import-wide extras. These are additive and must never fail the row -
        // the lead itself is already safely written.
        if ($tagIds = $options['tag_ids'] ?? []) {
            $lead->tags()->syncWithPivotValues($tagIds, ['tagged_by' => $import->uploaded_by], false);
        }

        foreach ($options['product_ids'] ?? [] as $productId) {
            $lead->leadProducts()->create(['product_id' => $productId]);
        }

        if (isset($values['note'])) {
            $lead->notes()->create([
                'user_id' => $import->uploaded_by,
                'body' => $values['note'],
            ]);
        }

        $this->recordRow($import, $rowNumber, $row, ImportRowStatus::Imported, null, $lead->id);
    }

    // -----------------------------------------------------------------------
    // Row validation
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, string>  $values
     * @return string|null the reason the row is unusable, or null if it is fine
     */
    private function validateRow(array $values): ?string
    {
        $validator = Validator::make($values, [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'alt_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return (string) $validator->errors()->first();
        }

        // Checked separately so the message names the real problem. "The phone
        // field is required" and "0912-345" is not a mobile number send the
        // operator to two different fixes.
        if (! PhoneNumber::isValid($values['phone'])) {
            return 'Not a valid 10-digit mobile number.';
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Bookkeeping
    // -----------------------------------------------------------------------

    /**
     * Writes the row outcome and advances the counters in ONE statement.
     *
     * The counters are incremented in SQL rather than read-modify-written in
     * PHP: with several workers on the `imports` queue, two rows finishing at
     * the same moment would otherwise overwrite each other's count and the
     * import would never reach its total.
     *
     * @param  array<string, string>  $row
     */
    private function recordRow(
        LeadImport $import,
        int $rowNumber,
        array $row,
        ImportRowStatus $status,
        ?string $message = null,
        ?int $leadId = null,
    ): void {
        DB::transaction(function () use ($import, $rowNumber, $row, $status, $message, $leadId) {
            LeadImportRow::create([
                'lead_import_id' => $import->id,
                'row_number' => $rowNumber,
                'status' => $status,
                'lead_id' => $leadId,
                'message' => $message !== null ? Str::limit($message, 480) : null,
                // Only rejected rows keep their source data: it is what the
                // operator needs to fix the file, and holding a full second
                // copy of every imported lead would be PII for no purpose.
                'data' => $status === ImportRowStatus::Imported ? null : $row,
            ]);

            LeadImport::whereKey($import->id)->update([
                'processed_rows' => DB::raw('processed_rows + 1'),
                $status->counterColumn() => DB::raw($status->counterColumn().' + 1'),
                'updated_at' => now(),
            ]);
        });

        $this->finaliseIfComplete($import->refresh());
    }

    /**
     * Marks the import finished once every row is accounted for.
     *
     * The status change is a CONDITIONAL update, so when the last few rows land
     * together exactly one worker wins and the import is finalised once.
     */
    public function finaliseIfComplete(LeadImport $import): void
    {
        if ($import->isFinished() || $import->processed_rows < $import->total_rows) {
            return;
        }

        $status = ($import->invalid_rows > 0 || $import->duplicate_rows > 0)
            ? ImportStatus::CompletedWithErrors
            : ImportStatus::Completed;

        $won = LeadImport::whereKey($import->id)
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->update([
                'status' => $status->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if ($won > 0) {
            Log::info('Lead import finished.', [
                'lead_import_id' => $import->id,
                'status' => $status->value,
                'imported' => $import->imported_rows,
                'duplicates' => $import->duplicate_rows,
                'invalid' => $import->invalid_rows,
            ]);
        }
    }

    public function markFailed(LeadImport $import, string $reason): void
    {
        $import->forceFill([
            'status' => ImportStatus::Failed,
            'failure_reason' => Str::limit($reason, 1000),
            'finished_at' => now(),
        ])->save();

        Log::error('Lead import failed.', [
            'lead_import_id' => $import->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Deletes the stored file, keeping the import record and its report.
     *
     * The file is a bulk PII payload; the report is an audit trail. They have
     * different lifetimes on purpose (SEC-PII-05).
     */
    public function purgeFile(LeadImport $import): void
    {
        if ($import->fileWasPurged()) {
            return;
        }

        Storage::disk($import->disk)->delete($import->stored_path);

        $import->forceFill(['stored_path' => null])->save();

        // The rejected-row snapshots are PII too and go with the file.
        $import->rows()->whereNotNull('data')->update(['data' => null]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @param array<int, ImportLeadRow> $chunk */
    private function dispatchChunk(array $chunk): void
    {
        foreach ($chunk as $job) {
            dispatch($job);
        }
    }

    /**
     * Keeps only the options the import is allowed to act on.
     *
     * Whitelisted rather than stored wholesale: `options` is written straight
     * from request input, and anything unexpected in it would be read back as
     * trusted configuration by the row jobs.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function sanitiseOptions(array $options): array
    {
        return array_filter([
            'lead_source_id' => isset($options['lead_source_id']) ? (int) $options['lead_source_id'] : null,
            'campaign_id' => isset($options['campaign_id']) ? (int) $options['campaign_id'] : null,
            'tag_ids' => array_map('intval', $options['tag_ids'] ?? []),
            'product_ids' => array_map('intval', $options['product_ids'] ?? []),
            'auto_assign' => (bool) ($options['auto_assign'] ?? false),
        ], fn ($value) => $value !== null && $value !== []);
    }
}
