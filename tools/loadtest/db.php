<?php

/**
 * Create or drop the disposable load-test database (T-43, docs/TODO.md).
 *
 * A standalone PDO script rather than an Artisan command on purpose: an
 * Artisan command boots the framework against `config('database')`, which
 * already names a database that may not exist yet - `php artisan migrate`
 * against a not-yet-created database just fails. This has to run BEFORE the
 * app ever connects, using a DSN with no database name at all, so it works
 * regardless of which of this project's several `.env.*` files ends up
 * pointing at MariaDB.
 *
 * Usage: php tools/loadtest/db.php <create|drop> <database-name>
 *
 * Deliberately refuses any name that is not obviously a load-test database
 * (T-43's own instructions: never run this against `marketing_crm`, the
 * `marketing_crm_test*` suite databases, or any other project's database on
 * this shared MariaDB instance).
 */

[, $action, $database] = array_pad($argv, 3, null);

if (! in_array($action, ['create', 'drop'], true) || $database === null) {
    fwrite(STDERR, "Usage: php tools/loadtest/db.php <create|drop> <database-name>\n");
    exit(1);
}

if (! preg_match('/^[A-Za-z0-9_]+_loadtest$/', $database)) {
    fwrite(STDERR, sprintf(
        "Refusing to %s database \"%s\" - name must end in \"_loadtest\" so this ".
        "can never touch a real dev/test database by mistake.\n",
        $action,
        $database,
    ));
    exit(1);
}

$host = getenv('LOADTEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('LOADTEST_DB_PORT') ?: '3306';
$user = getenv('LOADTEST_DB_USERNAME') ?: 'root';
$pass = getenv('LOADTEST_DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Could not connect to MariaDB/MySQL: '.$e->getMessage()."\n");
    exit(1);
}

if ($action === 'create') {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Created {$database}.\n";
} else {
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    echo "Dropped {$database}.\n";
}
