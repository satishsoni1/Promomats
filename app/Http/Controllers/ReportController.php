<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentApprovalAction;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The four reports spec REQ-48 asks for. Kept as one controller/one view with
 * a `report=` tab switch rather than four separate CRUD-style resources -
 * these are read-only aggregations over data that already has a system of
 * record elsewhere (DocumentApprovalAction, DocumentStageAssignee,
 * DocumentWorkflowInstance), not a new domain concept of their own.
 */
class ReportController extends Controller
{
    protected const REPORTS = ['approvals', 'sla', 'revisions', 'workflows'];

    public function index(Request $request)
    {
        $this->authorize('viewReports', Document::class);

        $report = in_array($request->get('report'), self::REPORTS, true) ? $request->get('report') : 'approvals';
        $rows = $this->{'build' . ucfirst($report) . 'Report'}();

        return view('reports.index', compact('report', 'rows'));
    }

    public function export(Request $request, string $report)
    {
        $this->authorize('viewReports', Document::class);
        abort_unless(in_array($report, self::REPORTS, true), 404);

        $rows = $this->{'build' . ucfirst($report) . 'Report'}();
        $filename = "{$report}-report-" . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($rows->isNotEmpty()) {
                fputcsv($out, array_keys($rows->first()));
            }
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Document / Brand / Version / Stage / Reviewer / Status / Submitted / Completed / Turnaround.
     * One row per signed decision (DocumentApprovalAction is the system of
     * record for those); turnaround is matched back to the assignee row's
     * assigned_at for that same instance+stage+reviewer.
     */
    protected function buildApprovalsReport()
    {
        return DocumentApprovalAction::query()
            ->with(['document.brand', 'version', 'stage', 'actor', 'instance'])
            ->orderByDesc('acted_at')
            ->get()
            ->map(function (DocumentApprovalAction $action) {
                $assignee = DocumentStageAssignee::where('document_workflow_instance_id', $action->document_workflow_instance_id)
                    ->where('workflow_stage_id', $action->workflow_stage_id)
                    ->where('user_id', $action->acted_by)
                    ->first();

                $turnaroundHours = $assignee?->assigned_at
                    ? round($assignee->assigned_at->diffInMinutes($action->acted_at) / 60, 1)
                    : null;

                return [
                    'Document' => $action->document?->reference_no . ' - ' . $action->document?->title,
                    'Brand' => $action->document?->brand?->name ?? '',
                    'Version' => $action->version?->version_no ?? '',
                    'Stage' => $action->stage?->name ?? '',
                    'Reviewer' => $action->signed_name ?? $action->actor?->name ?? '',
                    'Status' => $action->decisionLabel(),
                    'Submitted Date' => $action->instance?->started_at?->format('Y-m-d H:i') ?? '',
                    'Completed Date' => $action->acted_at?->format('Y-m-d H:i') ?? '',
                    'Turnaround (hrs)' => $turnaroundHours ?? '',
                ];
            });
    }

    /**
     * Reviewer / Total Tasks / Completed / Overdue / Average Time / SLA %.
     * "Overdue" counts anything ever flagged (overdue_notified_at set) or
     * still pending past its SLA right now - a completed-late task still
     * counts against SLA%, it just isn't "currently" overdue.
     */
    protected function buildSlaReport()
    {
        $reviewerIds = DocumentStageAssignee::query()->distinct()->pluck('user_id');
        $users = User::whereIn('id', $reviewerIds)->get()->keyBy('id');

        return DocumentStageAssignee::query()
            ->with('stage')
            ->get()
            ->groupBy('user_id')
            ->map(function ($tasks, $userId) use ($users) {
                $completed = $tasks->where('status', 'acted');
                $overdueEver = $tasks->filter(fn ($t) => $t->overdue_notified_at !== null || $t->isOverdue());
                $onTime = $completed->reject(fn ($t) => $t->overdue_notified_at !== null);

                $avgMinutes = $completed->filter(fn ($t) => $t->assigned_at && $t->acted_at)
                    ->avg(fn ($t) => $t->assigned_at->diffInMinutes($t->acted_at));

                return [
                    'Reviewer' => $users[$userId]?->name ?? "User #{$userId}",
                    'Total Tasks' => $tasks->count(),
                    'Completed' => $completed->count(),
                    'Overdue' => $overdueEver->count(),
                    'Average Time (hrs)' => $avgMinutes ? round($avgMinutes / 60, 1) : '',
                    'SLA %' => $completed->count() ? round(($onTime->count() / $completed->count()) * 100) . '%' : '',
                ];
            })
            ->sortByDesc(fn ($row) => $row['Total Tasks'])
            ->values();
    }

    /**
     * Document / Version / Reviewer / Reason / Date - every "approved with
     * changes" decision, the system's record of a requested revision.
     */
    protected function buildRevisionsReport()
    {
        return DocumentApprovalAction::query()
            ->where('decision', 'approved_with_changes')
            ->with(['document', 'version', 'actor'])
            ->orderByDesc('acted_at')
            ->get()
            ->map(fn (DocumentApprovalAction $action) => [
                'Document' => $action->document?->reference_no . ' - ' . $action->document?->title,
                'Version' => $action->version?->version_no ?? '',
                'Reviewer' => $action->signed_name ?? $action->actor?->name ?? '',
                'Reason' => $action->comments ?? '',
                'Date' => $action->acted_at?->format('Y-m-d H:i') ?? '',
            ]);
    }

    /**
     * Workflow / Documents / Completed / Pending / Average Duration - one row
     * per workflow template that has ever been used.
     */
    protected function buildWorkflowsReport()
    {
        return DocumentWorkflowInstance::query()
            ->with('template')
            ->get()
            ->groupBy('workflow_template_id')
            ->map(function ($instances) {
                $completed = $instances->whereIn('status', ['approved', 'approved_with_changes', 'rejected']);
                $avgMinutes = $completed->filter(fn ($i) => $i->started_at && $i->completed_at)
                    ->avg(fn ($i) => $i->started_at->diffInMinutes($i->completed_at));

                return [
                    'Workflow' => $instances->first()->template?->name ?? 'Unknown',
                    'Documents' => $instances->pluck('document_id')->unique()->count(),
                    'Completed' => $completed->count(),
                    'Pending' => $instances->where('status', 'running')->count(),
                    'Average Duration (days)' => $avgMinutes ? round($avgMinutes / 1440, 1) : '',
                ];
            })
            ->sortByDesc('Documents')
            ->values();
    }
}
