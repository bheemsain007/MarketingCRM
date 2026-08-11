<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data only. No demo leads, users or calls are seeded here - this
 * seeder must be safe to run against production, where inventing fake leads
 * would corrupt every report.
 *
 * Demo/sample data belongs in a separate DemoSeeder, added when needed.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            ProductSeeder::class,
            LeadSourceSeeder::class,
        ]);
    }
}
