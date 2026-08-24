<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Real Himalaya Wellness people named in the two PromoMats workflow briefs
 * (HimalayaPromoMatsPdfWorkflowSeeder / HimalayaPromoMatsDocWorkflowSeeder), which
 * wire specific individuals - not generic roles - as stage approvers.
 *
 * Every account is created with a random, unusable initial password and
 * must_change_password=true: nobody can sign in with it, so it does not need to be
 * distributed. Give each person access via the normal "Forgot password" flow (or an
 * admin-triggered reset) rather than sharing a shared/default password for real
 * corporate accounts.
 */
class HimalayaWellnessUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Roles named in the briefs that don't already exist under this exact name
        // (the system already has similarly-scoped but differently-named roles, e.g.
        // "Chairperson's Office", "TM/AGM Marketing", "Regulatory Level 1/2" - kept
        // distinct rather than reused, so these two workflows' approver lists stay
        // exactly what the brief specified).
        $newRoles = ['Brand Manager', 'Agency', 'TM/AGM', 'Medical', 'Regulatory', 'Legal', 'Chairperson', 'Content Creator'];
        foreach ($newRoles as $name) {
            Role::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'is_system' => false]);
        }

        $people = [
            ['name' => 'Devansh', 'email' => 'devansh.parikh@himalayawellness.com', 'dept' => "Chairperson's Office", 'roles' => ['Chairperson']],
            ['name' => 'Dr Hemanth', 'email' => 'dr.hemanth@himalayawellness.com', 'dept' => 'Marketing', 'roles' => ['TM/AGM']],
            ['name' => 'Rengarajan', 'email' => 'rengarajan.p@himalayawellness.com', 'dept' => "Chairperson's Office", 'roles' => ['Chairperson']],
            ['name' => 'Prathamesh', 'email' => 'prathamesh.pandurkar@himalayawellness.com', 'dept' => 'Marketing', 'roles' => ['TM/AGM']],
            ['name' => 'Bhupender', 'email' => 'bhupender.singh@himalayawellness.com', 'dept' => 'Marketing', 'roles' => ['TM/AGM']],
            ['name' => 'Kounnteya', 'email' => 'kounnteya.maurya@himalayawellness.com', 'dept' => 'Legal', 'roles' => ['Legal']],
            ['name' => 'Aditi', 'email' => 'aditi.dasgupta@himalayawellness.com', 'dept' => 'Agency', 'roles' => ['Agency', 'Legal']],
            ['name' => 'Ann', 'email' => 'ann.francis@himalayawellness.com', 'dept' => 'Medical', 'roles' => ['Medical']],
            ['name' => 'Dhanu', 'email' => 'dhanu@himalayawellness.com', 'dept' => 'Marketing', 'roles' => ['Brand Manager', 'Regulatory']],
            ['name' => 'Smriti', 'email' => 'smriti.singh@himalayawellness.com', 'dept' => 'Regulatory', 'roles' => ['Regulatory']],
            ['name' => 'Akansha', 'email' => 'akansha.thakur@himalayawellness.com', 'dept' => 'Medical', 'roles' => ['Medical']],
            ['name' => 'Monika', 'email' => 'monika.pant@himalayawellness.com', 'dept' => 'Content', 'roles' => ['Document Owner']],
            ['name' => 'Deepak', 'email' => 'deepak.db@himalayawellness.com', 'dept' => 'Content', 'roles' => ['Content Creator']],
            ['name' => 'Rathna', 'email' => 'rathna.b@himalayawellness.com', 'dept' => 'Content', 'roles' => ['Content Manager']],
            ['name' => 'Jalba', 'email' => 'jalba.rc@himalayawellness.com', 'dept' => 'Content', 'roles' => ['Content Manager']],
        ];

        foreach ($people as $p) {
            $user = User::firstOrCreate(
                ['email' => $p['email']],
                [
                    'name' => $p['name'],
                    'employee_code' => 'HW-' . strtoupper(Str::slug($p['name'], '')),
                    'department' => $p['dept'],
                    'designation' => $p['roles'][0],
                    // Unusable random hash - nobody can log in with this. Real access is
                    // granted via password reset, not by sharing this value.
                    'password' => Hash::make(Str::random(40)),
                    'is_active' => true,
                    'must_change_password' => true,
                ]
            );

            $roleIds = Role::whereIn('name', $p['roles'])->pluck('id');
            $user->roles()->syncWithoutDetaching($roleIds);
        }

        $this->command?->info('Himalaya Wellness users seeded (15). Accounts have no usable password - send each person a password-reset link to grant access.');
    }
}
