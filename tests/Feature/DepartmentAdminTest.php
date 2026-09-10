<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Department-scoped administration: the "Department Admin" role + users.admin_department
 * gets a user into /admin but limited to Users / Workflows / Dashboards for one
 * department. See AuthServiceProvider gates and the scoped Admin\* controllers.
 */
class DepartmentAdminTest extends TestCase
{
    use RefreshDatabase;

    private Role $globalAdminRole;
    private Role $deptAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Manage Users', 'Manage Workflows', 'View All Documents', 'View Reports'] as $p) {
            Permission::create(['name' => $p, 'slug' => \Illuminate\Support\Str::slug($p), 'group' => 'x']);
        }
        $this->globalAdminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'is_system' => true]);
        $this->deptAdminRole = Role::create(['name' => 'Department Admin', 'slug' => 'department-admin', 'is_system' => false]);
    }

    private function deptAdmin(string $department): User
    {
        $u = User::factory()->create(['department' => $department, 'admin_department' => $department]);
        $u->roles()->attach($this->deptAdminRole);

        return $u;
    }

    public function test_a_plain_user_cannot_reach_the_admin_area(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_a_department_admin_reaches_users_and_workflows_but_not_global_only_screens(): void
    {
        $admin = $this->deptAdmin('Scientific Publications');

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.workflows.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dashboards.index'))->assertOk();

        // Global-admin only:
        $this->actingAs($admin)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.brands.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.mail-settings.index'))->assertForbidden();
    }

    public function test_user_list_is_scoped_to_the_admins_department(): void
    {
        $admin = $this->deptAdmin('Legal');
        $inDept = User::factory()->create(['department' => 'Legal', 'name' => 'Ledger Legal']);
        $outOfDept = User::factory()->create(['department' => 'Marketing', 'name' => 'Marky Marketing']);

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertSee('Ledger Legal')
            ->assertDontSee('Marky Marketing');
    }

    public function test_department_admin_creates_users_only_in_their_department_and_cannot_mint_admins(): void
    {
        $admin = $this->deptAdmin('Legal');

        // Role outside the allowed set (Admin) is rejected.
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Nope', 'email' => 'nope@example.test',
            'department' => 'Legal', 'roles' => [$this->globalAdminRole->id],
        ])->assertSessionHasErrors('roles.0');

        // Wrong department is rejected.
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Wrong Dept', 'email' => 'wrong@example.test',
            'department' => 'Marketing', 'roles' => [$this->deptAdminRole->id],
        ])->assertSessionHasErrors('department');

        // Valid: department admin can create a normal user in their own department.
        $plainRole = Role::create(['name' => 'Reviewer', 'slug' => 'reviewer']);
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Good User', 'email' => 'good@example.test',
            'department' => 'Legal', 'roles' => [$plainRole->id],
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', ['email' => 'good@example.test', 'department' => 'Legal']);
    }

    public function test_department_admin_cannot_touch_another_departments_user(): void
    {
        $admin = $this->deptAdmin('Legal');
        $other = User::factory()->create(['department' => 'Marketing']);
        $role = Role::create(['name' => 'Reviewer', 'slug' => 'reviewer']);

        $this->actingAs($admin)->post(route('admin.users.roles.update', $other), ['roles' => [$role->id]])
            ->assertForbidden();
        $this->actingAs($admin)->post(route('admin.users.toggle-active', $other))
            ->assertForbidden();
    }

    public function test_department_admin_workflow_access_is_scoped_by_template_department(): void
    {
        $admin = $this->deptAdmin('Legal');

        $mine = WorkflowTemplate::create(['name' => 'Legal WF', 'code' => 'LEG_WF', 'department' => 'Legal', 'is_active' => true, 'created_by' => $admin->id]);
        $theirs = WorkflowTemplate::create(['name' => 'Mktg WF', 'code' => 'MKT_WF', 'department' => 'Marketing', 'is_active' => true, 'created_by' => $admin->id]);

        $this->actingAs($admin)->get(route('admin.workflows.index'))
            ->assertSee('Legal WF')->assertDontSee('Mktg WF');

        $this->actingAs($admin)->get(route('admin.workflows.edit', $mine))->assertOk();
        $this->actingAs($admin)->get(route('admin.workflows.edit', $theirs))->assertForbidden();

        // A new workflow is forced into the admin's own department.
        $this->actingAs($admin)->post(route('admin.workflows.store'), [
            'name' => 'Another Legal WF', 'code' => 'LEG_WF2', 'department' => 'Legal',
        ])->assertRedirect();
        $this->assertDatabaseHas('workflow_templates', ['code' => 'LEG_WF2', 'department' => 'Legal']);
    }
}
