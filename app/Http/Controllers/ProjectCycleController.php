<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectCycle;
use Illuminate\Http\Request;

class ProjectCycleController extends Controller
{
    /**
     * Any user can add a cycle to a project (self-serve, same governance level as
     * creating the project itself) - a cycle is just a labeled sub-round, not an
     * access boundary.
     */
    public function store(Request $request, Project $project)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $cycle = ProjectCycle::create([
            ...$validated,
            'project_id' => $project->id,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('projects.cycles.show', [$project, $cycle])->with('status', "Cycle \"{$cycle->name}\" created.");
    }

    public function show(Project $project, ProjectCycle $cycle)
    {
        abort_unless($cycle->project_id === $project->id, 404);

        $cycle->load('creator');
        $documents = $cycle->documents()->with(['owner', 'currentVersion'])->latest()->paginate(20);
        $breakdown = $cycle->statusBreakdown();

        // Documents in this project but not yet in any cycle, or in a different
        // cycle of the same project - either way, fair game to (re)assign here.
        $availableDocuments = $project->documents()->where(fn ($q) => $q->whereNull('cycle_id')->orWhere('cycle_id', '!=', $cycle->id))
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'title', 'cycle_id']);

        return view('projects.cycles.show', compact('project', 'cycle', 'documents', 'breakdown', 'availableDocuments'));
    }

    public function update(Request $request, Project $project, ProjectCycle $cycle)
    {
        abort_unless($cycle->project_id === $project->id, 404);
        $this->authorizeManage($request, $cycle);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'in:active,completed,archived'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $cycle->update($validated);

        return back()->with('status', 'Cycle updated.');
    }

    public function destroy(Request $request, Project $project, ProjectCycle $cycle)
    {
        abort_unless($cycle->project_id === $project->id, 404);
        $this->authorizeManage($request, $cycle);

        if (! $cycle->isEmpty()) {
            return back()->withErrors(['cycle' => 'This cycle still has documents assigned - move or unassign those first.']);
        }

        $cycle->delete();

        return redirect()->route('projects.show', $project)->with('status', 'Cycle removed.');
    }

    /**
     * Assigns (or reassigns) an existing document to this cycle - keeps
     * document.project_id in sync with the cycle's own project, so a document's
     * project and cycle can never point at two different projects.
     */
    public function assignDocument(Request $request, Project $project, ProjectCycle $cycle)
    {
        abort_unless($cycle->project_id === $project->id, 404);

        $validated = $request->validate([
            'document_id' => ['required', 'exists:documents,id'],
        ]);

        $document = Document::findOrFail($validated['document_id']);
        abort_unless(
            $request->user()->id === $document->owner_id || $request->user()->can('access-admin'),
            403,
            'Only the document owner or an admin can move it into a cycle.'
        );

        $document->update(['cycle_id' => $cycle->id, 'project_id' => $project->id]);

        return back()->with('status', "\"{$document->title}\" added to cycle \"{$cycle->name}\".");
    }

    protected function authorizeManage(Request $request, ProjectCycle $cycle): void
    {
        $user = $request->user();
        abort_unless(
            $user->id === $cycle->created_by || $user->id === $cycle->project->lead_id || $user->id === $cycle->project->created_by || $user->can('access-admin'),
            403,
            'Only the cycle\'s creator, the project\'s lead/creator, or an admin can manage this cycle.'
        );
    }
}
