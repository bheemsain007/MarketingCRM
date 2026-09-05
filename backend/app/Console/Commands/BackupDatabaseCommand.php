<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Full database backup, encrypted at rest (SEC-OPS-05, DEPLOYMENT §8).
 *
 * "Automated daily full ... encrypted at rest" is a policy line in a document
 * until something actually runs it. This is that something: `mysqldump` piped
 * straight into Laravel's own `Crypt` facade (APP_KEY - the same trust anchor
 * `SettingsService` already uses for provider credentials), so a backup file
 * copied off this server is unreadable without the key that never leaves it.
 *
 * The dump never touches disk unencrypted. `mysqldump`'s stdout is captured
 * into memory and encrypted before the first `Storage::put()` call - there is
 * no plaintext temp file for a crash, a `df` snapshot, or another process on
 * the same host to read in between.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'crm:backup {--label= : A note stored in the filename, e.g. "pre-deploy"}';

    protected $description = 'Dump the database and store it encrypted (SEC-OPS-05)';

    public function handle(): int
    {
        $connection = config('database.connections.mysql');
        $mysqldump = (string) config('backup.mysqldump_path');

        $this->components->info('Dumping '.$connection['database'].'@'.$connection['host'].' ...');

        try {
            $dump = $this->dump($connection, $mysqldump);
        } catch (RuntimeException $e) {
            // Caught here rather than left to crash the process: this runs
            // unattended off the scheduler, and a bad exit code the scheduler
            // can log is the point - an uncaught exception's stack trace in a
            // cron log nobody reads accomplishes the same thing worse.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($dump === '') {
            $this->components->error('mysqldump produced no output - refusing to store an empty backup.');

            return self::FAILURE;
        }

        $encrypted = Crypt::encryptString($dump);

        // Freed as soon as it is encrypted - the plaintext dump has no reason
        // to live any longer than it takes to hand it to Crypt.
        unset($dump);

        $filename = sprintf(
            '%s/%s%s.sql.enc',
            config('backup.directory'),
            now()->format('Y-m-d_His'),
            $this->option('label') ? '_'.Str::slug((string) $this->option('label')) : '',
        );

        Storage::disk(config('backup.disk'))->put($filename, $encrypted);

        $bytes = Storage::disk(config('backup.disk'))->size($filename);

        $this->components->info(sprintf(
            'Backup written: %s (%s encrypted bytes).',
            $filename,
            number_format($bytes),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function dump(array $connection, string $mysqldump): string
    {
        $process = new Process([
            $mysqldump,
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            // --single-transaction takes a consistent InnoDB snapshot without
            // locking tables for the duration of the dump - a backup that
            // blocks writes for however long the database takes to grow is
            // not one anyone will let run daily.
            '--single-transaction',
            '--routines',
            '--triggers',
            $connection['database'],
        ]);

        // MYSQL_PWD rather than --password=: the latter is visible to anyone
        // who can read this process's argv (e.g. `ps`/Task Manager) for the
        // life of the dump, on a host where other processes may be watching.
        $process->setEnv(['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
