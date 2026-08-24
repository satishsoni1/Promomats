<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One demo login per role in the approval-flow diagram, so every stage of every
 * workflow can actually be clicked through end to end. All demo accounts share the
 * same password - this is seed data for local/demo use only, never run this seeder
 * against a real deployment. See CREDENTIALS.md for the printed table.
 */
class DemoUsersSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Demo@12345';

    public function run(): void
    {
        $demoUsers = [
            ['email' => 'owner@promomats.test', 'name' => 'Olivia Owner', 'role' => 'Document Owner', 'dept' => 'Marketing'],
            ['email' => 'contentmanager@promomats.test', 'name' => 'Carl Contentmanager', 'role' => 'Content Manager', 'dept' => 'Content'],
            ['email' => 'tmagm@promomats.test', 'name' => 'Tara TmAgm', 'role' => 'TM/AGM Marketing', 'dept' => 'Marketing'],
            ['email' => 'reg1@promomats.test', 'name' => 'Raj Regulatory1', 'role' => 'Regulatory Level 1', 'dept' => 'Regulatory'],
            ['email' => 'reg2@promomats.test', 'name' => 'Rina Regulatory2', 'role' => 'Regulatory Level 2', 'dept' => 'Regulatory'],
            ['email' => 'legal1@promomats.test', 'name' => 'Leo Legal1', 'role' => 'Legal Level 1', 'dept' => 'Legal'],
            ['email' => 'legal2@promomats.test', 'name' => 'Lena Legal2', 'role' => 'Legal Level 2', 'dept' => 'Legal'],
            ['email' => 'rnd@promomats.test', 'name' => 'Ravi RnD', 'role' => 'R&D', 'dept' => 'R&D'],
            ['email' => 'chairperson@promomats.test', 'name' => 'Chandra Chairperson', 'role' => "Chairperson's Office", 'dept' => "Chairperson's Office"],
            ['email' => 'designinternal@promomats.test', 'name' => 'Diya DesignInternal', 'role' => 'Design (Internal)', 'dept' => 'Design'],
            ['email' => 'contentcreator@promomats.test', 'name' => 'Cody ContentCreator', 'role' => 'Content Creator (Int/Ext)', 'dept' => 'Content'],
            ['email' => 'contentagency@promomats.test', 'name' => 'Amy ContentAgency', 'role' => 'Content Agency', 'dept' => 'Agency'],
        ];

        foreach ($demoUsers as $u) {
            $user = User::firstOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'employee_code' => 'DEMO-' . strtoupper(str($u['role'])->slug()),
                    'department' => $u['dept'],
                    'designation' => $u['role'],
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'is_active' => true,
                    'must_change_password' => false,
                ]
            );

            $role = Role::where('name', $u['role'])->first();
            if ($role) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }
        }

        $this->command?->info('Demo users seeded. Password for all: ' . self::DEMO_PASSWORD);
    }
}
