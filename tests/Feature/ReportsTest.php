<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithReportAccess(): User
    {
        $permission = Permission::firstOrCreate(['slug' => 'view-reports'], ['name' => 'View Reports', 'group' => 'reports']);
        $role = Role::create(['name' => 'Report Viewer', 'slug' => 'report-viewer']);
        $role->permissions()->attach($permission);

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_a_user_without_the_view_reports_permission_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
    }

    public function test_each_report_tab_renders_for_a_user_with_access(): void
    {
        $user = $this->userWithReportAccess();

        foreach (['approvals', 'sla', 'revisions', 'workflows'] as $report) {
            $this->actingAs($user)
                ->get(route('reports.index', ['report' => $report]))
                ->assertOk()
                ->assertSee(match ($report) {
                    'approvals' => 'Approval Report',
                    'sla' => 'SLA Report',
                    'revisions' => 'Revision Report',
                    'workflows' => 'Workflow Report',
                });
        }
    }

    public function test_csv_export_streams_for_each_report(): void
    {
        $user = $this->userWithReportAccess();

        foreach (['approvals', 'sla', 'revisions', 'workflows'] as $report) {
            $response = $this->actingAs($user)->get(route('reports.export', ['report' => $report]));
            $response->assertOk();
            $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        }
    }

    public function test_an_unknown_report_key_404s(): void
    {
        $user = $this->userWithReportAccess();

        $this->actingAs($user)->get(route('reports.export', ['report' => 'nonsense']))->assertNotFound();
    }
}
