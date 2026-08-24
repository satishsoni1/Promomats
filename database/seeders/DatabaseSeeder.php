<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'System Admin',
                'employee_code' => 'ADM001',
                'password' => Hash::make('ChangeMe123!'), // change immediately after first login
                'is_active' => true,
            ]
        );

        $adminRole = Role::where('slug', 'admin')->first();
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $this->call(DemoUsersSeeder::class);

        $this->call(PharmaWorkflowSeeder::class);
        $this->call(PharmaWorkflow2Seeder::class);
        $this->call(PharmaWorkflow3Seeder::class);

        $this->call(HimalayaWellnessUsersSeeder::class);
        $this->call(HimalayaPromoMatsPdfWorkflowSeeder::class);
        $this->call(HimalayaPromoMatsDocWorkflowSeeder::class);

        $this->call(BrandAndDocumentTypeSeeder::class);

        $this->call(ClaimsDemoSeeder::class);
    }
}
