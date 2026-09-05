<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

/**
 * Shared base for every browser test (T-42, Phase 28).
 *
 * `DatabaseTruncation` rather than `RefreshDatabase`/`DatabaseMigrations`
 * per-test: the browser is driven by a SEPARATE `php artisan serve` process
 * from the one PHPUnit runs in, so a transaction opened here (what
 * `RefreshDatabase` relies on) is invisible to the queries that process
 * makes on the browser's behalf - the served app would see none of a
 * test's setup. Truncation issues real, committed statements both
 * processes agree on, at the cost of a full `migrate:fresh` once per run
 * instead of once per test (NFR-10: correctness over speed here).
 *
 * `$seeder` re-seeds roles and permissions after every truncation - almost
 * every journey needs a role to exist before a user can be attached to one.
 */
abstract class DuskTestCase extends BaseTestCase
{
    use DatabaseTruncation;

    /** @var class-string */
    protected $seeder = RolePermissionSeeder::class;

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}
