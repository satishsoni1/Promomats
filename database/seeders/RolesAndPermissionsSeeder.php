<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'Manage Users', 'group' => 'users'],
            ['name' => 'Manage Roles', 'group' => 'roles'],
            ['name' => 'Manage Workflows', 'group' => 'workflows'],
            ['name' => 'Upload Documents', 'group' => 'documents'],
            ['name' => 'Approve Documents', 'group' => 'documents'],
            ['name' => 'View All Documents', 'group' => 'documents'],
            ['name' => 'View Reports', 'group' => 'reports'],
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(
                ['slug' => Str::slug($p['name'])],
                ['name' => $p['name'], 'group' => $p['group']]
            );
        }

        // Roles mapped directly from the pharma approval diagram, plus Admin for system administration.
        $roles = [
            'Admin' => true,
            'TM/AGM Marketing' => false,
            'Document Owner' => false,
            'Content Manager' => false,
            'Content Creator (Int/Ext)' => false,
            'Content Agency' => false,
            'Design (Internal)' => false,
            'Regulatory Level 1' => false,
            'Regulatory Level 2' => false,
            'Legal Level 1' => false,
            'Legal Level 2' => false,
            'R&D' => false,
            "Chairperson's Office" => false,
        ];

        foreach ($roles as $name => $isSystem) {
            $role = Role::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_system' => $isSystem]
            );

            if ($name === 'Admin') {
                $role->permissions()->sync(Permission::pluck('id'));
            } elseif (in_array($name, ['TM/AGM Marketing', 'Document Owner'])) {
                $role->permissions()->sync(
                    Permission::whereIn('slug', ['upload-documents', 'view-all-documents', 'approve-documents'])->pluck('id')
                );
            } else {
                $role->permissions()->sync(
                    Permission::where('slug', 'approve-documents')->pluck('id')
                );
            }
        }
    }
}
