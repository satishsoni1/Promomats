<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Doubles as the project-status dashboard: every project any user can see, each
     * with its document-status breakdown and an overdue-approval flag, so scanning
     * this one page answers "how is each initiative doing" without opening every
     * document individually.
     */
    public function index(Request $request)
    {
        $projects = Project::withCount('documents')
            ->with('lead')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when(! $request->filled('status'), fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')
            ->get();

        // Both loops below used to run 2 extra queries *per project* (a status-count
        // query and a documents-with-workflow query) - fine for a handful of projects,
        // a real N+1 once there are dozens. Batched into two queries total instead:
        // one grouped status-count across every project, one eager-loaded documents
        // fetch with its overdue-relevant relations, both keyed back by project_id.
        $projectIds = $projects->pluck('id');

        $statusCounts = Document::whereIn('project_id', $projectIds)
            ->selectRaw('project_id, status, count(*) as c')
            ->groupBy('project_id', 'status')
            ->get()
            ->groupBy('project_id');

        $documentsByProject = Document::whereIn('project_id', $projectIds)
            ->with('activeWorkflowInstance.pendingAssignees')
            ->get()
            ->groupBy('project_id');

        $projects->each(function ($project) use ($statusCounts, $documentsByProject) {
            $counts = ($statusCounts[$project->id] ?? collect())->pluck('c', 'status');
            $breakdown = array_fill_keys(array_keys(Project::STATUS_BUCKETS), 0);
            foreach (Project::STATUS_BUCKETS as $bucket => $statuses) {
                foreach ($statuses as $status) {
                    $breakdown[$bucket] += (int) ($counts[$status] ?? 0);
                }
            }
            $project->breakdown = $breakdown;

            // Reuses DocumentStageAssignee::isOverdue() (per-stage SLA, not a flat 48h)
            // rather than re-deriving the condition here, so this always agrees with
            // the red-flag shown on the document page itself.
            $project->overdueCount = ($documentsByProject[$project->id] ?? collect())
                ->sum(fn ($doc) => $doc->activeWorkflowInstance?->pendingAssignees->filter(fn ($a) => $a->isOverdue())->count() ?? 0);
        });

        $unassignedCount = Document::whereNull('project_id')->count();

        return view('projects.index', compact('projects', 'unassignedCount'));
    }

    public function create()
    {
        return view('projects.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'target_audience' => ['nullable', 'string', 'in:' . implode(',', array_keys(\App\Support\TargetAudience::LABELS))],
            'lead_id' => ['nullable', 'exists:users,id'],
        ]);

        $project = Project::create([
            ...$validated,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('projects.show', $project)->with('status', 'Project created.');
    }

    public function show(Request $request, Project $project)
    {
        $project->load(['lead', 'creator', 'referenceAttachments.uploader', 'cycles.creator']);

        $documents = $project->documents()
            ->with(['owner', 'currentVersion', 'cycle'])
            ->latest()
            ->paginate(20);

        $breakdown = $project->statusBreakdown();
        $users = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        // Cycle count is always small (a handful of rounds per project, not
        // hundreds), so a per-cycle breakdown query here doesn't need the same
        // batching treatment as index()'s cross-project version.
        $project->cycles->each(fn ($cycle) => $cycle->breakdown = $cycle->statusBreakdown());

        $user = $request->user();
        $canManage = $user->id === $project->created_by || $user->id === $project->lead_id || $user->can('access-admin');

        return view('projects.show', compact('project', 'documents', 'breakdown', 'users', 'canManage'));
    }

    public function edit(Project $project)
    {
        $users = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        return view('projects.edit', compact('project', 'users'));
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeManage($request, $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string'],
            'target_audience' => ['nullable', 'string', 'in:' . implode(',', array_keys(\App\Support\TargetAudience::LABELS))],
            'lead_id' => ['nullable', 'exists:users,id'],
            'status' => ['required', 'in:active,archived'],
        ]);

        $project->update($validated);

        return redirect()->route('projects.show', $project)->with('status', 'Project updated.');
    }

    /**
     * Only the project's creator, its lead, or an admin can edit/archive it - anyone
     * can create a project (self-serve), but not everyone should be able to rename or
     * archive someone else's.
     */
    protected function authorizeManage(Request $request, Project $project): void
    {
        $user = $request->user();
        abort_unless(
            $user->id === $project->created_by || $user->id === $project->lead_id || $user->can('access-admin'),
            403,
            'Only the project\'s creator, lead, or an admin can edit this project.'
        );
    }
}
