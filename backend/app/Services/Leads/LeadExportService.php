<?php

namespace App\Services\Leads;

use App\Enums\ExportStatus;
use App\Enums\Permission;
use App\Jobs\LeadExportJob;
use App\Models\Lead;
use App\Models\LeadExport;
use App\Models\User;
use App\Support\Csv\CsvFormulaGuard;
use App\Support\PhoneNumber;
use App\Support\QueryOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Bulk lead CSV export (FR-LEAD-12, SEC-PII-04).
 *
 * Mirrors LeadImportService's shape: the request only validates the filters
 * and queues the run (NFR-06); everything expensive - re-deriving the
 * audience and writing the file - happens entirely inside the job, off the
 * request thread.
 */
class LeadExportService
{
    /**
     * Filter fields an export accepts - identical to LeadController::index's
     * allowlist, so an export always matches what the lead list screen was
     * showing (FR-LEAD-12). `sort` and `include` are deliberately absent: a
     * CSV has no pagination order to preserve and every column is written to
     * every row regardless of relation loading.
     */
    private const ALLOWED_FILTERS = [
        'status', 'temperature', 'priority', 'assigned_to',
        'lead_source_id', 'campaign_id', 'city', 'state',
        'is_suppressed', 'created_at', 'last_contacted_at',
    ];

    /** Columns written to every export, in this order. */
    private const COLUMNS = [
        'id', 'name', 'company', 'phone', 'phone_formatted', 'alt_phone', 'email',
        'city', 'state', 'country', 'status', 'temperature', 'score', 'priority',
        'is_suppressed', 'source', 'assigned_to', 'created_at', 'last_contacted_at',
    ];

    /**
     * Validates the filters can be applied at all, then queues the run.
     *
     * `buildQuery()` is called here purely for its validation side effect
     * (QueryOptions throws on an unsupported filter field): a bad filter is
     * then a 422 the operator sees immediately, rather than an async failure
     * only visible by polling - the same reasoning as the shape check
     * `LeadImportService::createFromUpload()` runs before queuing a file.
     *
     * @param  array<string, mixed>  $filters
     */
    public function request(User $actor, array $filters): LeadExport
    {
        $this->buildQuery($actor, $filters);

        $export = LeadExport::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'user_id' => $actor->id,
            'filters' => $filters,
            'requested_at' => now(),
        ]);

        LeadExportJob::dispatch($export->id);

        // Refreshed rather than returned as-is, for two reasons: `status` has
        // no PHP-side default and is only ever set by the DATABASE column
        // default until the job (or, on the sync queue driver, this very
        // call) writes it - and on the sync driver dispatch() above already
        // ran the job to completion, so an unrefreshed object would report a
        // stale `pending` even though the export is already done. Mirrors
        // LeadImportService::createFromUpload()'s own `refresh()` before
        // returning.
        return $export->refresh();
    }

    /**
     * Runs one export end to end (FR-LEAD-12).
     *
     * Re-checks the requester's permission and re-derives the query from
     * their CURRENT data scope rather than trusting anything decided at
     * request time: a role can change between the request and the job
     * actually running, and a Manager must not end up seeing leads outside
     * their normal scope just because an export happened to be in flight
     * when their access changed.
     */
    public function process(LeadExport $export): void
    {
        if ($export->isFinished()) {
            return;
        }

        $actor = $export->requester;

        if ($actor === null || ! $actor->hasPermission(Permission::LeadsExport)) {
            $this->markFailed($export, 'The requesting user no longer has permission to export leads.');

            return;
        }

        $export->forceFill(['status' => ExportStatus::Processing])->save();

        $disk = (string) config('crm.exports.disk');
        $directory = trim((string) config('crm.exports.directory'), '/');
        $relativePath = $directory.'/'.Str::uuid()->toString().'.csv';

        try {
            $rowCount = $this->writeCsv(
                $disk,
                $relativePath,
                $this->buildQuery($actor, $export->filters ?? []),
            );

            $retentionDays = max(1, (int) config('crm.exports.retention_days'));

            $export->forceFill([
                'status' => ExportStatus::Completed,
                'disk' => $disk,
                'file_path' => $relativePath,
                'row_count' => $rowCount,
                'completed_at' => now(),
                'expires_at' => now()->addDays($retentionDays),
            ])->save();
        } catch (Throwable $e) {
            // A partially written file is worse than none - it looks like a
            // real, if truncated, export rather than a failed one.
            Storage::disk($disk)->delete($relativePath);

            $this->markFailed($export, $e->getMessage());
        }
    }

    public function markFailed(LeadExport $export, string $reason): void
    {
        $export->forceFill([
            'status' => ExportStatus::Failed,
            'failure_reason' => Str::limit($reason, 1000),
            'completed_at' => now(),
        ])->save();

        Log::error('Lead export failed.', [
            'lead_export_id' => $export->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Re-derives the lead query for stored filters under the actor's data
     * scope - the same filtering and scoping LeadController::index applies,
     * so an export matches exactly what its requester was looking at
     * (SEC-AUTHZ-03).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Lead>
     */
    public function buildQuery(User $actor, array $filters): Builder
    {
        $request = Request::create('/', 'GET', array_filter([
            'filter' => is_array($filters['filter'] ?? null) ? $filters['filter'] : null,
        ], fn ($value) => $value !== null));

        $options = new QueryOptions($request, allowedFilters: self::ALLOWED_FILTERS);

        $query = Lead::query();

        // Data scope applied BEFORE any stored filter, so an export can never
        // widen what its requester may see (SEC-AUTHZ-03), matching
        // LeadController::index's own ordering.
        $actor->applyDataScope($query);

        if (filter_var($filters['with_archived'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->withTrashed();
        }

        if (is_string($filters['q'] ?? null) && $filters['q'] !== '') {
            $search = $filters['q'];

            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $options->applyTo($query);
    }

    /**
     * Streams the query to a CSV on the given disk, chunked so the job never
     * holds the whole result set in memory (the write-side mirror of
     * CsvReader's row-at-a-time reasoning on the import path).
     *
     * @param  Builder<Lead>  $query
     * @return int rows written
     */
    private function writeCsv(string $disk, string $relativePath, Builder $query): int
    {
        $storage = Storage::disk($disk);
        $storage->makeDirectory(dirname($relativePath));

        $absolutePath = $storage->path($relativePath);
        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new RuntimeException('The export file could not be created.');
        }

        try {
            fputcsv($handle, CsvFormulaGuard::neutraliseRow(self::COLUMNS));

            $rowCount = 0;
            $chunkSize = max(1, (int) config('crm.exports.chunk_size'));

            $query->with(['source', 'assignedUser'])
                ->chunkById($chunkSize, function ($leads) use ($handle, &$rowCount) {
                    foreach ($leads as $lead) {
                        fputcsv($handle, CsvFormulaGuard::neutraliseRow($this->row($lead)));
                        $rowCount++;
                    }
                });

            return $rowCount;
        } finally {
            fclose($handle);
        }
    }

    /**
     * One CSV row for one lead.
     *
     * The phone number is written raw (E.164), matching LeadResource's own
     * precedent - `leads.export` being a separate, audited permission is
     * SEC-PII-04's control, not masking the number on top of it.
     *
     * @return array<int, string>
     */
    private function row(Lead $lead): array
    {
        return [
            (string) $lead->id,
            (string) $lead->name,
            (string) $lead->company,
            (string) $lead->phone_e164,
            (string) PhoneNumber::format($lead->phone_e164),
            (string) $lead->alt_phone_e164,
            (string) $lead->email,
            (string) $lead->city,
            (string) $lead->state,
            (string) $lead->country,
            $lead->status->value,
            $lead->temperature->value,
            (string) $lead->score,
            (string) $lead->priority,
            $lead->is_suppressed ? '1' : '0',
            (string) $lead->source?->name,
            (string) $lead->assignedUser?->name,
            (string) $lead->created_at?->toIso8601String(),
            (string) $lead->last_contacted_at?->toIso8601String(),
        ];
    }
}
