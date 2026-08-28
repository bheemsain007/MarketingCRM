<?php

namespace App\Console\Commands;

use App\Models\LeadExport;
use App\Services\Leads\LeadExportService;
use Illuminate\Console\Command;

/**
 * Deletes generated lead export files past their download window (SEC-PII-05).
 *
 * An export file is the whole lead database as plain text - every name, phone
 * number and email address the filters matched. `expires_at` already stops the
 * download endpoint serving one, but a refused download is not a deleted file:
 * without this sweep every export ever requested sits on disk for ever, read by
 * nobody and protected by nothing but the filesystem.
 *
 * The FILE goes; the ROW stays, with its path nulled - it is the record of who
 * exported what and when, and destroying it would destroy the evidence that the
 * retention policy was honoured.
 *
 * No `--days` override, unlike `leads:purge-import-files`: an export carries its
 * own stored `expires_at`, so a window to override at purge time would contradict
 * the one the requester was actually promised.
 */
class PurgeLeadExportFilesCommand extends Command
{
    protected $signature = 'leads:purge-export-files {--dry-run : Report without deleting}';

    protected $description = 'Delete lead export files whose download window has closed';

    public function handle(LeadExportService $exports): int
    {
        if ($this->option('dry-run')) {
            $due = LeadExport::expired()->whereNotNull('file_path')->count();

            $this->info(sprintf('[dry run] %d export file(s) would be purged.', $due));

            return self::SUCCESS;
        }

        $purged = $exports->purgeExpired();

        $this->info(sprintf('%d export file(s) purged.', $purged));

        return self::SUCCESS;
    }
}
