<?php

namespace Tests\Feature\Ops;

use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves the backup actually restores, not just that it runs (SEC-OPS-05).
 *
 * "Backups exist" and "backups restore" are different claims - DEPLOYMENT §8
 * says an untested backup is not a backup, so this test is the thing that
 * makes the second claim checkable on every run rather than trusted once by
 * hand. It backs up the real test database, restores it into a throwaway
 * scratch database, and checks the restored data against the original
 * row-for-row - not just that the artisan commands exited 0.
 *
 * `DatabaseTruncation`, not `RefreshDatabase` - the same reason
 * `DuskTestCase` uses it (see its docblock): `crm:backup` shells out to
 * `mysqldump` as a genuinely separate OS process with its own connection,
 * which cannot see rows sitting inside this test's still-open
 * `RefreshDatabase` transaction. The first version of this test used
 * `RefreshDatabase` and backed up zero rows every time - a real bug this
 * class's own first run caught, not a hypothetical one.
 *
 * Needs a real mysqldump/mysql on this machine (BACKUP_MYSQLDUMP_PATH /
 * BACKUP_MYSQL_PATH in .env.testing) - there is no meaningful way to fake a
 * database dump/restore and still prove anything.
 */
class BackupRestoreDrillTest extends TestCase
{
    use DatabaseTruncation;

    private const SCRATCH_DATABASE = 'marketing_crm_restore_drill_test';

    protected function tearDown(): void
    {
        // Runs even if an assertion above failed - a scratch database left
        // behind by a failed test run would corrupt the NEXT run's drill.
        $this->dropScratchDatabase();

        parent::tearDown();
    }

    #[Test]
    public function a_backup_restores_into_a_fresh_database_with_every_row_intact(): void
    {
        Storage::fake('backups');

        // Real content to check for, not just a row count - a restore that
        // silently truncated a column would still pass a bare count comparison.
        $leads = Lead::factory()->count(12)->create();
        $expectedCount = Lead::query()->count();
        $marker = $leads->first()->name;

        Artisan::call('crm:backup', ['--label' => 'drill-test']);

        $files = Storage::disk('backups')->files('database');
        $this->assertNotEmpty($files, 'crm:backup wrote no file to the backups disk.');

        $this->createScratchDatabase();

        $exitCode = Artisan::call('crm:restore', [
            'file' => $files[0],
            '--database' => self::SCRATCH_DATABASE,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, 'crm:restore did not exit successfully: '.Artisan::output());

        $restored = $this->scratchConnection();

        $restoredCount = (int) $restored->query('SELECT COUNT(*) AS c FROM leads')->fetch()['c'];
        $this->assertSame(
            $expectedCount,
            $restoredCount,
            'The restored database has a different number of leads than the original.',
        );

        $markerFound = $restored->prepare('SELECT COUNT(*) AS c FROM leads WHERE name = ?');
        $markerFound->execute([$marker]);
        $this->assertSame(
            1,
            (int) $markerFound->fetch()['c'],
            'A specific lead\'s name did not survive the backup/restore round trip.',
        );

        $migrationsRestored = (int) $restored->query('SELECT COUNT(*) AS c FROM migrations')->fetch()['c'];
        $this->assertGreaterThan(
            0,
            $migrationsRestored,
            'The migrations table did not survive the restore - schema metadata was lost, not just data.',
        );
    }

    #[Test]
    public function an_empty_dump_is_refused_rather_than_stored_as_a_backup(): void
    {
        // A silently-empty backup is worse than a loud failure - it looks
        // like protection while protecting nothing.
        config(['database.connections.mysql.database' => 'a_database_that_does_not_exist_'.uniqid()]);

        Storage::fake('backups');

        $exitCode = Artisan::call('crm:backup');

        $this->assertNotSame(0, $exitCode, 'crm:backup should fail against a database that cannot be dumped.');
        $this->assertEmpty(Storage::disk('backups')->files('database'));
    }

    #[Test]
    public function restore_refuses_without_an_explicit_database_name(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('database/fake.sql.enc', 'irrelevant - never reached');

        $exitCode = Artisan::call('crm:restore', ['file' => 'database/fake.sql.enc']);

        $this->assertNotSame(0, $exitCode, 'crm:restore must refuse when --database is not given.');
    }

    private function createScratchDatabase(): void
    {
        $connection = config('database.connections.mysql');
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s', $connection['host'], $connection['port']),
            $connection['username'],
            $connection['password'],
        );

        $pdo->exec('DROP DATABASE IF EXISTS `'.self::SCRATCH_DATABASE.'`');
        $pdo->exec('CREATE DATABASE `'.self::SCRATCH_DATABASE.'`');
    }

    private function dropScratchDatabase(): void
    {
        $connection = config('database.connections.mysql');

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s', $connection['host'], $connection['port']),
                $connection['username'],
                $connection['password'],
            );
            $pdo->exec('DROP DATABASE IF EXISTS `'.self::SCRATCH_DATABASE.'`');
        } catch (\Throwable) {
            // Best-effort cleanup - a failure here must never mask the real
            // test result via tearDown().
        }
    }

    private function scratchConnection(): PDO
    {
        $connection = config('database.connections.mysql');

        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], self::SCRATCH_DATABASE),
            $connection['username'],
            $connection['password'],
        );
    }
}
