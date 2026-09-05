<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes encrypted database backups past their retention window (SEC-OPS-05).
 *
 * Matches `leads:purge-export-files`'s reasoning: `crm:backup` running daily
 * with nothing ever removing old copies is not a backup policy, it is an
 * unbounded disk-usage leak with "encrypted" in its name. Unlike an export or
 * an import file, a backup has no owning row to null out - the file itself
 * *is* the record, so deleting it is the whole action.
 */
class PurgeBackupsCommand extends Command
{
    protected $signature = 'crm:purge-backups {--dry-run : Report without deleting}';

    protected $description = 'Delete database backups past crm.backup.retention_days (SEC-OPS-05)';

    public function handle(): int
    {
        $disk = Storage::disk(config('backup.disk'));
        $cutoff = now()->subDays((int) config('backup.retention_days'));

        $due = collect($disk->files(config('backup.directory')))
            ->filter(fn (string $path) => $disk->lastModified($path) < $cutoff->getTimestamp());

        if ($this->option('dry-run')) {
            $this->components->info(sprintf('[dry run] %d backup(s) would be purged.', $due->count()));

            return self::SUCCESS;
        }

        $due->each(fn (string $path) => $disk->delete($path));

        $this->components->info(sprintf('%d backup(s) purged.', $due->count()));

        return self::SUCCESS;
    }
}
