<#
.SYNOPSIS
    Runs the T-43 load-test harness (docs/TODO.md, FR-RPT-05) against a
    disposable database, then drops the database again.

.DESCRIPTION
    This is the documented, reusable entry point for load-testing campaign
    fan-out throughput and dashboard response time - see
    docs/LOAD_TEST_RESULTS.md for methodology and the last recorded run, and
    backend/app/Console/Commands/LoadTestCommand.php for what actually runs.

    Every run:
      1. Creates `marketing_crm_loadtest` (via db.php - a plain PDO script,
         because the database has to exist BEFORE Laravel can boot against it).
      2. Migrates it fresh, using backend/.env.loadtest
         (QUEUE_CONNECTION=database, matching production - see that file's
         own docblock for why this is not a shortcut).
      3. Runs `php artisan crm:load-test <scenario>`.
      4. Drops the database again, unless -Keep is passed.

    NEVER point this at `marketing_crm`, `marketing_crm_test`, or any of the
    shared `marketing_crm_test_{a,b,c,d}` databases other work on this
    machine uses concurrently - db.php refuses any name not ending in
    "_loadtest", and the Artisan command refuses to run against anything else
    too, but this script does not override either guard.

.PARAMETER Scenario
    campaign | dashboard | all (default: all)

.PARAMETER Leads
    Synthetic audience size for the campaign scenario (default: 8000).
    The stated design target is 50,000 (DispatchCampaign.php, CampaignService
    .php's own comments) - see docs/LOAD_TEST_RESULTS.md for why this harness
    tests smaller and extrapolates rather than seeding 50,000 every run.

.PARAMETER Days
    Days of history to seed for the dashboard scenario (default: 180).

.PARAMETER LeadsPerDay
    Leads/day seeded for the dashboard scenario (default: 60).

.PARAMETER Repeats
    HTTP requests fired per dashboard measurement (default: 30).

.PARAMETER Port
    Port for the throwaway `php artisan serve` the dashboard scenario starts
    and stops itself (default: 8781 - deliberately not 8000, so this never
    collides with a real dev server).

.PARAMETER Keep
    Do not drop the database afterwards. Useful for inspecting the seeded
    data or re-running `php artisan crm:load-test` by hand without
    re-seeding. The command itself re-truncates its own tables on every run,
    so re-running against a kept database is safe.

.EXAMPLE
    tools/loadtest/run.ps1
        Runs both scenarios at the defaults.

.EXAMPLE
    tools/loadtest/run.ps1 -Scenario campaign -Leads 20000
        Campaign scenario only, at a larger synthetic audience.

.EXAMPLE
    tools/loadtest/run.ps1 -Scenario dashboard -Days 365 -Keep
        Dashboard scenario over a year of history, database left in place
        afterwards for inspection (`mysql marketing_crm_loadtest`).
#>
param(
    [ValidateSet('campaign', 'dashboard', 'all')]
    [string]$Scenario = 'all',
    [int]$Leads = 8000,
    [int]$Days = 180,
    [int]$LeadsPerDay = 60,
    [int]$Repeats = 30,
    [int]$Port = 8781,
    [switch]$Keep
)

$ErrorActionPreference = 'Stop'

$ToolsDir = $PSScriptRoot
$Backend = (Resolve-Path (Join-Path $ToolsDir '..\..\backend')).Path
$Database = 'marketing_crm_loadtest'

Set-Location $Backend

Write-Host "==> Creating disposable database $Database"
php (Join-Path $ToolsDir 'db.php') create $Database
if ($LASTEXITCODE -ne 0) {
    throw "Could not create $Database - see db.php's output above."
}

try {
    Write-Host '==> Migrating (backend/.env.loadtest)'
    php artisan migrate --force --env=loadtest
    if ($LASTEXITCODE -ne 0) { throw 'Migration failed - see output above.' }

    Write-Host "==> Running: php artisan crm:load-test $Scenario"
    php artisan crm:load-test $Scenario `
        --leads=$Leads `
        --days=$Days `
        --leads-per-day=$LeadsPerDay `
        --repeats=$Repeats `
        --port=$Port `
        --env=loadtest
    if ($LASTEXITCODE -ne 0) { throw 'crm:load-test failed - see output above.' }
}
finally {
    if ($Keep) {
        Write-Host "==> -Keep passed: leaving $Database in place. Drop it later with:"
        Write-Host "    php $(Join-Path $ToolsDir 'db.php') drop $Database"
    }
    else {
        Write-Host "==> Dropping $Database"
        php (Join-Path $ToolsDir 'db.php') drop $Database
    }
}
