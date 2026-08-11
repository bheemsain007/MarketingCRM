<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;

/**
 * Baseline lead sources. Source quality (conversion rate per source) is what
 * decides where marketing spend goes (GLOSSARY §2.9), so these are seeded rather
 * than typed ad hoc - inconsistent source names make that report meaningless.
 */
class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            ['code' => 'FACEBOOK_ADS',  'name' => 'Facebook Lead Ads',  'category' => 'facebook'],
            ['code' => 'INSTAGRAM_ADS', 'name' => 'Instagram Lead Ads', 'category' => 'instagram'],
            ['code' => 'WEBSITE',       'name' => 'Website Enquiry',    'category' => 'website'],
            ['code' => 'REFERRAL',      'name' => 'Referral',           'category' => 'referral'],
            ['code' => 'WALK_IN',       'name' => 'Walk-in',            'category' => 'walk_in'],
            ['code' => 'CSV_IMPORT',    'name' => 'CSV / Excel Import', 'category' => 'import'],
            ['code' => 'MANUAL',        'name' => 'Manual Entry',       'category' => 'manual'],
            ['code' => 'COLD_CALL',     'name' => 'Cold Calling List',  'category' => 'other'],
        ];

        foreach ($sources as $source) {
            LeadSource::updateOrCreate(
                ['tenant_id' => 0, 'code' => $source['code']],
                ['name' => $source['name'], 'category' => $source['category'], 'is_active' => true]
            );
        }
    }
}
