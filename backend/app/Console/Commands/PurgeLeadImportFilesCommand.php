<?php

namespace App\Console\Commands;

use App\Models\LeadImport;
use App\Services\Leads\LeadImportService;
use Illuminate\Console\Command;

/**
 * Deletes uploaded import files past their retention window (SEC-PII-05).
 *
 * An import file is a plain-text list of hundreds or thousands of people's
 * names and phone numbers. Keeping it forever adds nothing - the leads are in
 * the database and the outcome report survives the purge - while steadily
 * growing the amount of PII sitting in a directory nobody looks at.
 *
 * The import record and its per-row statuses are NOT deleted; only the file
 * and the raw row snapshots of rejected rows go.
 */
class PurgeLeadImportFilesCommand extends Command
{
    protected $signature = 'leads:purge-import-files
                            {--days= : Override the configured retention window}';

    protected $description = 'Delete lead import files that are past their retention window';

    public function handle(LeadImportService $imports): int
    {
        $days = (int) ($this->option('days') ?? config('crm.imports.file_retention_days'));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // Only finished imports are eligible: a still-running import needs its
        // file, however old the record is.
        $stale = LeadImport::query()
            ->whereNotNull('stored_path')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $cutoff)
            ->get();

        foreach ($stale as $import) {
            $imports->purgeFile($import);

            $this->line(sprintf('Purged file for import #%d (%s).', $import->id, $import->original_filename));
        }

        $this->info(sprintf('%d import file(s) purged (older than %d days).', $stale->count(), $days));

        return self::SUCCESS;
    }
}
