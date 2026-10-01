<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentWorkTask;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * "Active Workflow" - everything in flight, Veeva-style (UAT feedback: inputs
 * showed up in the library for viewing only; they must also appear where action
 * is required, and each Brand Manager needs their own view of their jobs).
 *
 * Tabs:
 *  - action   what's waiting on me: approvals, revisions of my jobs, team work
 *  - mine     jobs I own as task owner that are still in flight
 *  - design   the Design Team queue (Design Team members only)
 *  - bm       one tab per Brand Manager with their jobs (admins see every Brand
 *             Manager; a Brand Manager sees their own)
 */
class ActiveWorkflowController extends Controller
{
    public const ACTIVE_STATUSES = ['draft', 'in_review', 'approved_with_changes_pending'];

    public function index(Request $request)
    {
        $user = $request->user()->loadMissing('roles');
        $tab = $request->query('tab', 'action');

        $myApprovals = $user->pendingApprovals()
            ->with(['instance.document.owner', 'instance.document.documentType', 'stage'])
            ->get()
            ->sortBy(fn (DocumentStageAssignee $a) => $a->dueAt()?->timestamp ?? PHP_INT_MAX)
            ->values();

        $myRevisions = Document::where('owner_id', $user->id)
            ->where('status', 'approved_with_changes_pending')
            ->whereDoesntHave('workTasks', fn ($q) => $q->open())
            ->with(['activeWorkflowInstance.resumeAtStage', 'approvalActions.actor', 'documentType'])
            ->latest('status_changed_at')
            ->get();

        $myWorkTasks = $user->openWorkTasksQuery()
            ->with(['document.owner', 'assignee', 'requester'])
            ->orderBy('due_at')
            ->get();

        $myJobs = $this->activeJobsFor($user->id);

        $designQueue = $user->isDesignTeam() || $user->canAdminister()
            ? DocumentWorkTask::open()->where('team', 'design')->with(['document.owner', 'assignee', 'requester'])->orderBy('due_at')->get()
            : collect();
        $designTeam = $designQueue->isNotEmpty() || $user->isDesignTeam() ? User::designTeam()->get(['id', 'name']) : collect();

        // Brand Manager tabs.
        $brandManagers = User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', config('promomats.roles.brand_manager', ['brand-manager'])))
            ->orderBy('name')
            ->get(['id', 'name']);
        if (! $user->canAdminister()) {
            $brandManagers = $brandManagers->where('id', $user->id)->values();
        }
        $selectedBm = $brandManagers->firstWhere('id', (int) $request->query('bm')) ?? $brandManagers->first();
        $bmJobs = $selectedBm ? $this->activeJobsFor($selectedBm->id) : collect();

        $counts = [
            'action' => $myApprovals->count() + $myRevisions->count() + $myWorkTasks->count(),
            'mine' => $myJobs->count(),
            'design' => $designQueue->count(),
        ];

        return view('workflow.active', compact(
            'tab', 'myApprovals', 'myRevisions', 'myWorkTasks', 'myJobs', 'designQueue', 'designTeam',
            'brandManagers', 'selectedBm', 'bmJobs', 'counts'
        ));
    }

    /**
     * A task owner's jobs still in flight, with who holds each one right now.
     */
    protected function activeJobsFor(int $ownerId)
    {
        return Document::where('owner_id', $ownerId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->with([
                'documentType', 'brand',
                'activeWorkflowInstance.pendingAssignees.user',
                'activeWorkflowInstance.pendingAssignees.stage',
                'workTasks' => fn ($q) => $q->open()->with('assignee'),
            ])
            ->latest('updated_at')
            ->get();
    }
}
