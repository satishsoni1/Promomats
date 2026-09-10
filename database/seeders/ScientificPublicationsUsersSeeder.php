<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The Scientific Publications team - a separate team from the PromoMats people in
 * HimalayaWellnessUsersSeeder, with its own roles and its own two workflows
 * (ScientificPublicationsWorkflow1Seeder / ...Workflow2Seeder). Sourced from
 * "Scientific Publications Workflows_User Details":
 *
 *   Production workflow 1 (content generation) - Content Developer, Content
 *   Reviewer, Content/Copy Editor.
 *   Production workflow 2 (proof generation)   - Graphic Designer, Content
 *   Developer, Project Lead, Proofreader.
 *   Approval workflow                          - AGM (Scientific Publications),
 *   Head (Regulatory Affairs), Head (Legal), Approver (Legal), Project Lead.
 *
 * Several people hold more than one role (e.g. every Project Lead is also a
 * content person; the two proofreaders are also copy editors) - each stage in the
 * workflow seeders is wired to the specific people the brief lists for it.
 *
 * As with HimalayaWellnessUsersSeeder, accounts are created with the shared default
 * password "welcome" and must_change_password = true (client can sign in as any of
 * them for demo/UAT and is forced to set a real password on first login). Switch to
 * an unusable random hash for production.
 *
 * NOTE: the brief lists Shruthi V Kumar's address on the "himalayawell.com"
 * domain while everyone else is "himalayawellness.com" - kept exactly as written
 * so it matches the source of truth; fix it here if that turns out to be a typo.
 */
class ScientificPublicationsUsersSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'Content Developer',
            'Content Reviewer',
            'Copy Editor',
            'Proofing Editor',
            'Graphic Designer',
            'Project Lead',
            'AGM Scientific Publications',
            'Head Regulatory Affairs',
            'Head Legal',
            'Approver Legal',
            'Line Manager',
        ];

        foreach ($roles as $name) {
            Role::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'is_system' => false]);
        }

        $people = [
            ['name' => 'Dr Anna Chackanackuzhy', 'email' => 'dr.anna.c@himalayawellness.com', 'dept' => 'Scientific Publications', 'roles' => ['Content Developer', 'Project Lead']],
            ['name' => 'Dr Priyanka R', 'email' => 'dr.priyanka.r@himalayawellness.com', 'dept' => 'Scientific Publications', 'roles' => ['Content Developer', 'Project Lead']],
            ['name' => 'Shruthi V Kumar', 'email' => 'shruthi.kumar@himalayawell.com', 'dept' => 'Scientific Publications', 'roles' => ['Content Reviewer', 'Project Lead']],
            ['name' => 'Dr Chaitra G', 'email' => 'dr.chaitra.g@himalayawellness.com', 'dept' => 'Scientific Publications', 'roles' => ['Content Reviewer', 'Project Lead']],
            ['name' => 'Harika GS', 'email' => 'harika.gs@himalayawellness.com', 'dept' => 'Scientific Publications', 'roles' => ['Copy Editor', 'Proofing Editor', 'Project Lead']],
            ['name' => 'Shruthi Murali', 'email' => 'shruthi.murali@himalayawellness.com', 'dept' => 'Scientific Publications', 'roles' => ['Copy Editor', 'Proofing Editor', 'Project Lead']],
            ['name' => 'Dayanand Rao', 'email' => 'dayanand.rao@himalayawellness.com', 'dept' => 'Scientific Publications', 'roles' => ['Graphic Designer']],
            ['name' => 'Santhosh G', 'email' => 'santosh.g@himalayawellness.com', 'dept' => 'Scientific Publications', 'roles' => ['Graphic Designer']],
            ['name' => 'Monesh NP', 'email' => 'monesh.np@himalayawellness.com', 'dept' => 'Scientific Publications', 'roles' => ['Graphic Designer']],
            ['name' => 'Dr Jayashree Keshav', 'email' => 'dr.jayashree@himalayawellness.com', 'dept' => 'Scientific Publications', 'roles' => ['AGM Scientific Publications', 'Line Manager']],
            ['name' => 'Dr Vijendra', 'email' => 'dr.vijendra@himalayawellness.com', 'dept' => 'Regulatory Affairs', 'roles' => ['Head Regulatory Affairs']],
            ['name' => 'Julie Buragohain', 'email' => 'julie.buragohain@himalayawellness.com', 'dept' => 'Legal', 'roles' => ['Head Legal']],
            ['name' => 'Swaroop Mahesh', 'email' => 'swaroop.mahesh@himalayawellness.com', 'dept' => 'Legal', 'roles' => ['Approver Legal']],
        ];

        foreach ($people as $p) {
            $user = User::firstOrCreate(
                ['email' => $p['email']],
                [
                    'name' => $p['name'],
                    'employee_code' => 'HWSP-' . strtoupper(Str::slug($p['name'], '')),
                    'department' => $p['dept'],
                    'designation' => $p['roles'][0],
                    // Shared default password for demo/UAT - forced change on first login.
                    // Swap for Hash::make(Str::random(40)) in production.
                    'password' => Hash::make('welcome'),
                    'is_active' => true,
                    'must_change_password' => true,
                ]
            );

            $roleIds = Role::whereIn('name', $p['roles'])->pluck('id');
            $user->roles()->syncWithoutDetaching($roleIds);
        }

        $this->command?->info('Scientific Publications users seeded (13). Default password "welcome" (must change on first login).');
    }
}
