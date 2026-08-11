<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The seven real products (PROJECT_REQUIREMENTS §1.1, P1-P7).
 *
 * Idempotent - safe to re-run; uses updateOrCreate on the code so re-seeding a
 * live database never duplicates or wipes existing product rows.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['code' => 'NEWS_PORTAL',      'name' => 'News Portal Development',       'delivery_type' => 'saas'],
            ['code' => 'NGO_PORTAL',       'name' => 'NGO Portal Development',        'delivery_type' => 'saas'],
            ['code' => 'EPAPER',           'name' => 'Epaper Development',            'delivery_type' => 'saas'],
            ['code' => 'BUSINESS_WEBSITE', 'name' => 'Business Website Development',  'delivery_type' => 'project'],
            ['code' => 'SHOPPING_PORTAL',  'name' => 'Shopping Portal Development',   'delivery_type' => 'project'],
            ['code' => 'MATRIMONIAL',      'name' => 'Matrimonial Portal Development', 'delivery_type' => 'saas'],
            ['code' => 'NEWS_POSTING',     'name' => 'News Posting in Your News Portal', 'delivery_type' => 'service'],
        ];

        foreach ($products as $i => $product) {
            // tenant_id 0 = the default tenant. Never null: a nullable tenant_id
            // would disable the UNIQUE(tenant_id, code) index entirely.
            Product::updateOrCreate(
                ['tenant_id' => 0, 'code' => $product['code']],
                [
                    'name' => $product['name'],
                    'delivery_type' => $product['delivery_type'],
                    'currency' => 'INR',
                    'is_active' => true,
                    'sort_order' => $i + 1,
                ]
            );
        }
    }
}
