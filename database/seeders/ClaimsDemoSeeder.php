<?php

namespace Database\Seeders;

use App\Models\Claim;
use App\Models\ContentModule;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A handful of demo claims + one content module, so the claims library and modular
 * content screens have real data to show on first login instead of empty states.
 */
class ClaimsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->first();

        $claim1 = Claim::firstOrCreate(
            ['match_text' => '45% reduction in risk of disease progression'],
            [
                'body' => 'Based on a pre-specified interim analysis of the pivotal Phase III trial (HR 0.55, 95% CI 0.46-0.65, p<0.001).',
                'category' => 'Efficacy',
                'product' => 'Immunobooster',
                'country' => 'Global',
                'status' => 'approved',
                'created_by' => $admin->id,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]
        );
        $claim1->references()->firstOrCreate(['title' => 'Pivotal Phase III Trial, Final CSR (v3.0)'], [
            'citation' => 'Data on file. Median progression-free survival 25.2 months vs 14.0 months, chemotherapy arm.',
        ]);

        $claim2 = Claim::firstOrCreate(
            ['match_text' => 'Do not administer to patients with known hypersensitivity to any component of the formulation'],
            [
                'category' => 'Safety',
                'product' => 'Immunobooster',
                'country' => 'Global',
                'status' => 'approved',
                'created_by' => $admin->id,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]
        );
        $claim2->references()->firstOrCreate(['title' => 'Full Prescribing Information'], [
            'citation' => 'Section 4.3 - Contraindications.',
        ]);

        $draftClaim = Claim::firstOrCreate(
            ['match_text' => 'Over two years of median progression-free survival'],
            [
                'category' => 'Efficacy',
                'product' => 'Immunobooster',
                'status' => 'draft', // awaiting Legal/Regulatory sign-off before it can be used
                'created_by' => $admin->id,
            ]
        );

        $module = ContentModule::firstOrCreate(
            ['name' => 'Immunobooster - Standard Efficacy & Safety Block'],
            [
                'description' => 'The standard efficacy claim + mandatory safety statement every Immunobooster promotional piece must include.',
                'product' => 'Immunobooster',
                'country' => 'Global',
                'status' => 'approved',
                'created_by' => $admin->id,
            ]
        );

        if ($module->claims()->count() === 0) {
            $module->claims()->attach([$claim1->id => ['sequence_no' => 1], $claim2->id => ['sequence_no' => 2]]);
        }
    }
}
