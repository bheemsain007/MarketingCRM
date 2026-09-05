<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Restores a `crm:backup` file into a database (SEC-OPS-05).
 *
 * The counterpart nobody tests until the day they need it - "backups exist"
 * and "backups restore" are different claims, and this command is what makes
 * the second one checkable on demand rather than assumed.
 *
 * `--database` is REQUIRED, deliberately never defaulting to the connection's
 * own configured database: a restore is a destructive overwrite of whatever
 * is there, and a flag a person must type is a flag they read before they
 * type it. `ConfirmableTrait` (the same mechanism `migrate --force` uses)
 * additionally refuses in a production environment without `--force`, so an
 * unattended or scripted invocation cannot silently overwrite live data.
 */
class RestoreDatabaseCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'crm:restore
                            {file : Path on the backup disk, e.g. database/2026-09-06_120000.sql.enc}
                            {--database= : Target database name - required, never defaults}
                            {--force : Skip the production-environment confirmation}';

    protected $description = 'Restore an encrypted crm:backup file into a database (SEC-OPS-05)';

    public function handle(): int
    {
        $database = (string) $this->option('database');

        if ($database === '') {
            $this->components->error('--database is required. A restore overwrites whatever is in the '.
                'target database - naming it is not optional.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed(
            sprintf('This will OVERWRITE every table in "%s" with the contents of the backup.', $database)
        )) {
            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        $disk = Storage::disk(config('backup.disk'));

        if (! $disk->exists($path)) {
            $this->components->error('No backup found at "'.$path.'" on the "'.config('backup.disk').'" disk.');

            return self::FAILURE;
        }

        $this->components->info('Decrypting '.$path.' ...');

        try {
            $sql = Crypt::decryptString($disk->get($path));
        } catch (\Throwable $e) {
            $this->components->error('Could not decrypt this backup: '.$e->getMessage().
                '. Wrong APP_KEY, or the file is not one crm:backup produced.');

            return self::FAILURE;
        }

        $this->components->info('Restoring into "'.$database.'" ...');

        try {
            $this->restore($sql, $database);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Restore complete.');

        return self::SUCCESS;
    }

    private function restore(string $sql, string $database): void
    {
        $connection = config('database.connections.mysql');
        $mysql = (string) config('backup.mysql_path');

        // CREATE/DROP/ALTER during a restore need more than the application's
        // own least-privilege user holds (SEC-OPS-04) - see config/backup.php.
        $username = (string) config('backup.database.username');
        $password = (string) config('backup.database.password');

        $process = new Process([
            $mysql,
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$username,
            $database,
        ]);

        $process->setEnv(['MYSQL_PWD' => $password]);
        $process->setTimeout(3600);
        $process->setInput($sql);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysql restore failed: '.$process->getErrorOutput());
        }
    }
}
