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
 *  - action   what's waiting on me: approvals, my jobs back with me (revision
 *             needed / draft aligned), team work
 *  - mine     jobs I own as Brand Manager that are still in flight
 *  - design   the Design Team queue (Design Team members only)
 *  - agency   the Content Agency queue (agency users only)
 *  - bm       one tab per Brand Manager with their jobs (admins see every Brand
 *             Manager; a Brand Manager sees their own)
 */
class ActiveWorkflowController extends Controller
{
    public const ACTIVE_STATUSES = ['draft', 'in_review', 'approved_with_changes_pending', 'draft_aligned'];

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
            ->whereIn('status', Document::OWNER_ACTION_STATUSES)
            ->whereDoesntHave('workTasks', fn ($q) => $q->open())
            ->with(['activeWorkflowInstance.resumeAtStage', 'approvalActions.actor', 'documentType'])
            ->latest('status_changed_at')
            ->get();

        $myWorkTasks = $user->openWorkTasksQuery()
            ->with(['document.owner', 'assignee', 'requester'])
            ->orderBy('due_at')
            ->get();

        $myJobs = $this->activeJobsFor($user->id);

        $queue = fn (string $team) => $user->inTeam($team) || $user->canAdminister()
            ? DocumentWorkTask::open()->where('team', $team)->with(['document.owner', 'assignee', 'requester'])->orderBy('due_at')->get()
            : collect();
        $designQueue = $queue('design');
        $agencyQueue = $queue('agency');

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
            'agency' => $agencyQueue->count(),
        ];

        return view('workflow.active', compact(
            'tab', 'myApprovals', 'myRevisions', 'myWorkTasks', 'myJobs', 'designQueue', 'agencyQueue',
            'brandManagers', 'selectedBm', 'bmJobs', 'counts'
        ));
    }

    /**
     * A Brand Manager's jobs still in flight, with who holds each one right now.
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
