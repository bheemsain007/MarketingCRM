<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database backups (SEC-OPS-05, DEPLOYMENT §8)
    |--------------------------------------------------------------------------
    |
    | `crm:backup` dumps the database with mysqldump and encrypts the dump with
    | the application's own APP_KEY before writing it to disk - the same trust
    | anchor Settings already uses for provider credentials (SettingsService),
    | so this introduces no second secret to manage or rotate separately.
    |
    | mysqldump/mysql are resolved from config rather than assumed to be on
    | PATH: this dev machine's are under XAMPP, production's are wherever the
    | host's PHP/MySQL install puts them, and a hardcoded path would work on
    | exactly one of those.
    */

    'disk' => env('BACKUP_DISK', 'backups'),
    'directory' => 'database',

    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
    'mysql_path' => env('BACKUP_MYSQL_PATH', 'mysql'),

    /*
    | A restore has to CREATE/DROP/ALTER tables to rebuild the schema it is
    | restoring - privileges the application's own runtime user (`crm_user`)
    | correctly does NOT hold under SEC-OPS-04's least-privilege rule (no
    | DROP/GRANT in application credentials). Restore therefore needs its own,
    | more privileged database user - a normal DBA/ops credential, distinct
    | from anything the running application ever authenticates with. Falls
    | back to the main connection's credentials only so a dump (which needs no
    | elevated privilege) has a sane default; a restore attempted with an
    | unprivileged user fails loudly with the database's own permission error
    | rather than silently, so this default is safe even though it will not
    | be enough to actually restore anything.
    */
    'database' => [
        'username' => env('BACKUP_DB_USERNAME', env('DB_USERNAME')),
        'password' => env('BACKUP_DB_PASSWORD', env('DB_PASSWORD')),
    ],

    // How long a backup file is kept before `crm:purge-backups` removes it.
    // Encrypted backups of a database that itself changes daily lose their
    // recovery value long before this - kept generous so a restore drill run
    // days apart from a real incident does not find its evidence already gone.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

];
