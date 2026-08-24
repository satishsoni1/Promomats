<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentApprovalAction;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentWorkflowInstance;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * Every user's personal landing page - the analytics-lite counterpart to
     * Admin\DashboardController::index(), but scoped entirely to "my related
     * data": documents I own, approvals waiting on me, projects I'm involved in,
     * and decisions I've personally signed. Nothing here reads across the whole
     * org - that view is Admin > Dashboards, gated separately.
     */
    public function index()
    {
        $user = Auth::user();

        $ownedDocumentIds = Document::where('owner_id', $user->id)->pluck('id');

        $statusCounts = Document::where('owner_id', $user->id)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $myStatusBreakdown = $this->bucketize($statusCounts);
        $myDocumentsTotal = array_sum($myStatusBreakdown);

        // My documents uploaded per month, last 12 months (zero-filled) - same
        // geometry pattern as the org-wide chart, just filtered to owner_id.
        $months = collect(range(0, 11))->map(fn ($i) => Carbon::now()->subMonths(11 - $i)->startOfMonth());
        $createdByMonthRaw = Document::where('owner_id', $user->id)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, count(*) as c")
            ->where('created_at', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->groupBy('ym')->pluck('c', 'ym');
        $myDocumentsByMonth = $months->map(fn ($m) => [
            'label' => $m->format('M'),
            'count' => (int) ($createdByMonthRaw[$m->format('Y-m')] ?? 0),
        ]);

        // Average time-to-approve for my own completed documents - tells an owner
        // whether their submissions typically move quickly or stall.
        $completedInstances = DocumentWorkflowInstance::whereIn('document_id', $ownedDocumentIds)
            ->whereIn('status', ['approved', 'approved_with_changes', 'rejected'])
            ->whereNotNull('completed_at')
            ->get(['started_at', 'completed_at']);
        $myAvgApprovalDays = $completedInstances->isEmpty()
            ? null
            : round($completedInstances->avg(fn ($i) => $i->started_at->diffInHours($i->completed_at) / 24), 1);

        // Waiting on my approval, with an overdue flag per row (reuses the same
        // SLA helper as the admin overdue-tasks view).
        $pendingApprovals = $user->pendingApprovals()
            ->with(['instance.document', 'stage'])
            ->latest('assigned_at')
            ->get()
            ->map(fn ($assignee) => (object) [
                'assignee' => $assignee,
                'overdue' => $assignee->isOverdue(),
                'hours_waiting' => $assignee->hoursWaiting(),
            ]);

        $recentDocuments = Document::with(['owner', 'currentVersion'])
            ->where('owner_id', $user->id)
            ->latest()
            ->take(6)
            ->get();

        // My projects: any project I lead, or that contains at least one document
        // I own - status-bucketed the same way as everywhere else in the app.
        $myProjects = Project::where('lead_id', $user->id)
            ->orWhereIn('id', Document::where('owner_id', $user->id)->whereNotNull('project_id')->distinct()->pluck('project_id'))
            ->withCount(['documents' => fn ($q) => $q->where('owner_id', $user->id)])
            ->get();
        $projectStatusCounts = Document::where('owner_id', $user->id)
            ->whereIn('project_id', $myProjects->pluck('id'))
            ->selectRaw('project_id, status, count(*) as c')
            ->groupBy('project_id', 'status')
            ->get()
            ->groupBy('project_id');
        $myProjects = $myProjects->map(fn ($project) => [
            'project' => $project,
            'total' => $project->documents_count,
            'breakdown' => $this->bucketize(($projectStatusCounts[$project->id] ?? collect())->pluck('c', 'status')),
        ])->sortByDesc('total')->values();

        // My signing history: decisions I've personally e-signed, most recent first.
        $mySignatures = DocumentApprovalAction::where('acted_by', $user->id)
            ->whereNotNull('signed_name')
            ->with(['document', 'stage'])
            ->latest('acted_at')
            ->take(8)
            ->get();

        return view('dashboard', [
            'myDocumentsCount' => $myDocumentsTotal,
            'pendingApprovalsCount' => $pendingApprovals->count(),
            'inReviewCount' => (int) ($statusCounts['in_review'] ?? 0),
            'agingCount' => Document::where('owner_id', $user->id)->where('is_aging_flagged', true)->count(),
            'myAvgApprovalDays' => $myAvgApprovalDays,
            'myStatusBreakdown' => $myStatusBreakdown,
            'myDocumentsByMonth' => $myDocumentsByMonth,
            'recentDocuments' => $recentDocuments,
            'pendingApprovals' => $pendingApprovals,
            'myProjects' => $myProjects,
            'mySignatures' => $mySignatures,
        ]);
    }

    /**
     * Collapses raw per-status counts into the same 6 buckets used across
     * Project/ProjectCycle::statusBreakdown() and the admin dashboard, so a
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
