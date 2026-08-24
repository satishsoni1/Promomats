<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentWorkflowInstance;
use App\Models\Project;
use App\Models\ProjectCycle;
use App\Models\User;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $statusCounts = Document::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $materialsByStatus = collect(Document::STATUS_LABELS)->map(fn ($label, $status) => [
            'label' => $label,
            'count' => (int) ($statusCounts[$status] ?? 0),
        ])->values();

        $approvedMaterials = ($statusCounts['approved'] ?? 0) + ($statusCounts['approved_for_production'] ?? 0) + ($statusCounts['approved_for_distribution'] ?? 0);

        $completedInstances = DocumentWorkflowInstance::whereIn('status', ['approved', 'approved_with_changes', 'rejected'])
            ->whereNotNull('completed_at')
            ->get(['started_at', 'completed_at']);

        $avgApprovalDays = $completedInstances->isEmpty()
            ? null
            : round($completedInstances->avg(fn ($i) => $i->started_at->diffInHours($i->completed_at) / 24), 1);

        // Documents created per month, last 12 months (zero-filled).
        $months = collect(range(0, 11))->map(fn ($i) => Carbon::now()->subMonths(11 - $i)->startOfMonth());
        $createdByMonthRaw = Document::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, count(*) as c")
            ->where('created_at', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->groupBy('ym')->pluck('c', 'ym');
        $contentByMonth = $months->map(fn ($m) => [
            'label' => $m->format('M'),
            'count' => (int) ($createdByMonthRaw[$m->format('Y-m')] ?? 0),
        ]);

        $overdueAssignees = DocumentStageAssignee::where('status', 'pending')
            ->with(['user', 'stage', 'instance.document'])
            ->get()
            ->filter(fn ($assignee) => $assignee->isOverdue());

        $overdueTasks = $overdueAssignees
            ->map(fn ($assignee) => (object) [
                'assignee' => $assignee,
                'hours_waiting' => $assignee->hoursWaiting(),
                'sla_hours' => $assignee->slaHours(),
            ])
            ->sortByDesc('hours_waiting')
            ->values();

        // ---- Multi-level analytics: Project / Cycle / User ----

        $byProject = $this->projectBreakdown();
        $byCycle = $this->cycleBreakdown();
        $byUser = $this->userWorkload($overdueAssignees);

        // File-level: documents that need attention right now regardless of
        // approval-SLA status (the section above already covers that angle) -
        // revision loops and rejections, oldest first so the longest-stuck float up.
        $needsAttention = Document::whereIn('status', ['approved_with_changes_pending', 'rejected'])
            ->with('owner')
            ->orderBy('status_changed_at')
            ->limit(15)
            ->get();

        return view('admin.dashboards.index', compact(
            'materialsByStatus', 'approvedMaterials', 'avgApprovalDays', 'contentByMonth', 'overdueTasks',
            'byProject', 'byCycle', 'byUser', 'needsAttention'
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int, array>
     */
    protected function projectBreakdown()
    {
        $projects = Project::withCount('documents')->with('lead')->where('status', 'active')->orderByDesc('documents_count')->get();
        $projectIds = $projects->pluck('id');

        $statusCounts = Document::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, status, count(*) as c')
            ->groupBy('project_id', 'status')
            ->get()
            ->groupBy('project_id');

        return $projects->map(function ($project) use ($statusCounts) {
            $counts = ($statusCounts[$project->id] ?? collect())->pluck('c', 'status');
            return [
                'project' => $project,
                'total' => $project->documents_count,
                'breakdown' => $this->bucketize($counts),
            ];
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, array>
     */
    protected function cycleBreakdown()
    {
        $cycles = ProjectCycle::withCount('documents')->with('project')->where('status', 'active')->orderByDesc('documents_count')->get();
        $cycleIds = $cycles->pluck('id');

        $statusCounts = Document::whereIn('cycle_id', $cycleIds)
            ->selectRaw('cycle_id, status, count(*) as c')
            ->groupBy('cycle_id', 'status')
            ->get()
            ->groupBy('cycle_id');

        return $cycles->map(function ($cycle) use ($statusCounts) {
            $counts = ($statusCounts[$cycle->id] ?? collect())->pluck('c', 'status');
            return [
                'cycle' => $cycle,
                'total' => $cycle->documents_count,
                'breakdown' => $this->bucketize($counts),
            ];
        });
    }

    /**
     * Per-user workload: how many documents each active user owns (by status
     * bucket), how many approvals are currently sitting with them, and how many of
     * those are overdue - the "who's a bottleneck right now" view. Limited to users
     * with at least some activity so this doesn't list every never-used account.
     */
    protected function userWorkload($overdueAssignees)
    {
        $users = User::where('is_active', true)
            ->withCount(['ownedDocuments', 'pendingApprovals'])
            ->having('owned_documents_count', '>', 0)
            ->orHaving('pending_approvals_count', '>', 0)
            ->orderByDesc('pending_approvals_count')
            ->get();

        $userIds = $users->pluck('id');

        $ownedStatusCounts = Document::whereIn('owner_id', $userIds)
            ->selectRaw('owner_id, status, count(*) as c')
            ->groupBy('owner_id', 'status')
            ->get()
            ->groupBy('owner_id');

        $overdueByUser = $overdueAssignees->groupBy('user_id');

        return $users->map(function ($user) use ($ownedStatusCounts, $overdueByUser) {
            $counts = ($ownedStatusCounts[$user->id] ?? collect())->pluck('c', 'status');
            return [
                'user' => $user,
                'owned_total' => $user->owned_documents_count,
                'breakdown' => $this->bucketize($counts),
                'pending_approvals' => $user->pending_approvals_count,
                'overdue_approvals' => ($overdueByUser[$user->id] ?? collect())->count(),
            ];
        })->values();
    }

    /**
     * Collapses raw per-status counts into the same 6 buckets used across
     * Project/ProjectCycle::statusBreakdown() and the Projects dashboard, so a
     * status bucket always means the same thing everywhere in the app.
     */
    protected function bucketize($counts): array
    {
        $breakdown = array_fill_keys(array_keys(Project::STATUS_BUCKETS), 0);
        foreach (Project::STATUS_BUCKETS as $bucket => $statuses) {
            foreach ($statuses as $status) {
                $breakdown[$bucket] += (int) ($counts[$status] ?? 0);
            }
        }
        return $breakdown;
    }
}
