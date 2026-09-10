<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The Scientific Publications team's "Publications List" from the brief, seeded as
 * one Project per publication so the team's documents group under the title they
 * belong to (Probe, Evecare, Confido, ...) and share a per-publication reference
 * library. Lead is left unset - assign a Project Lead per publication later.
 */
class ScientificPublicationsProjectsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@globalspace.in')->first() ?? User::first();

        if (! $admin) {
            $this->command?->warn('ScientificPublicationsProjectsSeeder skipped: no users exist yet.');

            return;
        }

        $publications = [
            'Probe',
            'Capsule',
            'Himalaya Livline',
            'Himalaya Infoline',
            'Pediritz',
            'Alloveda',
            'Alloveda Hindi',
            'Evecare',
            'Asian Journal of Obstetrics & Gynecology Practice',
            'Confido',
            'All About Pets',
            'Vet Info-H',
        ];

        foreach ($publications as $name) {
            Project::firstOrCreate(
                ['name' => $name],
                [
                    'code' => 'SCIPUB-' . strtoupper(Str::slug($name)),
                    'description' => 'Scientific Publications - ' . $name,
                    'target_audience' => 'hcp',
                    'status' => 'active',
                    'created_by' => $admin->id,
                ]
            );
        }

        $this->command?->info('Scientific Publications projects seeded (' . count($publications) . ' publications).');
    }
}
