<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentWorkTask;
use App\Models\User;
use App\Services\WorkTaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Team "umbrella" work (internal Design Team or Content Agency): the Brand Manager
 * sends the creative / rework to a team, a team member takes it or assigns it to a
 * colleague, and they upload the file - which comes back to the Brand Manager.
 */
class WorkTaskController extends Controller
{
    public function __construct(protected WorkTaskService $tasks) {}

    /** Brand Manager: "send this to the Design Team / Content Agency". */
    public function store(Request $request, Document $document)
    {
        $this->authorize('manageTasks', $document);

        $validated = $request->validate([
            'instructions' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'resubmit_on_upload' => ['nullable', 'boolean'],
            'assign_to' => ['nullable', 'integer', 'exists:users,id'],
            'team' => ['nullable', 'in:design,agency'],
            'content_agency_id' => ['nullable', 'integer', 'exists:content_agencies,id'],
        ]);
        $team = $validated['team'] ?? 'design';

        $this->tasks->requestRework(
            document: $document,
            requestedBy: $request->user(),
            instructions: $validated['instructions'] ?? null,
            dueAt: ! empty($validated['due_date']) ? Carbon::parse($validated['due_date'])->endOfDay() : null,
            resubmitOnUpload: (bool) ($validated['resubmit_on_upload'] ?? false),
            assignTo: $validated['assign_to'] ?? null,
            team: $team,
            agencyId: $validated['content_agency_id'] ?? null,
        );

        return back()->with('status', 'Sent to the ' . DocumentWorkTask::TEAM_LABELS[$team] . '.');
    }

    /** Brand Manager: send a placeholder's brief straight to a team for the creative. */
    public function assignPlaceholder(Request $request, Document $document)
    {
        $this->authorize('manageTasks', $document);
        abort_unless($document->is_placeholder && $document->status === 'draft', 422, 'Only a placeholder that has not started can be sent this way.');

        $validated = $request->validate([
            'team' => ['required', 'in:design,agency'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'content_agency_id' => ['nullable', 'integer', 'exists:content_agencies,id'],
            'assign_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $this->tasks->requestArtwork(
            document: $document,
            requestedBy: $request->user(),
            instructions: $validated['instructions'] ?? null,
            dueAt: $document->due_date ? $document->due_date->copy()->endOfDay() : null,
            team: $validated['team'],
            agencyId: $validated['content_agency_id'] ?? null,
            assignTo: $validated['assign_to'] ?? null,
        );

        return back()->with('status', 'Brief sent to the ' . DocumentWorkTask::TEAM_LABELS[$validated['team']] . '.');
    }

    /** A team member takes the task themselves. */
    public function take(Request $request, DocumentWorkTask $task)
    {
        abort_unless($task->isForTeamOf($request->user()), 403);
        $this->tasks->assign($task, $request->user(), $request->user());

        return back()->with('status', 'You have taken this task.');
    }

    /**
     * Assign to a specific person: any member of the task's team, the Brand Manager,
     * or an admin. Agency users can hand work only to colleagues in their own agency
     * (WorkTaskService::assign() enforces who can receive it).
     */
    public function assign(Request $request, DocumentWorkTask $task)
    {
        $user = $request->user();
        abort_unless($task->isForTeamOf($user) || $user->can('manageTasks', $task->document), 403);

        $validated = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $designer = User::with('roles')->findOrFail($validated['user_id']);
        $this->tasks->assign($task, $designer, $user);

        return back()->with('status', "Assigned to {$designer->name}.");
    }

    /** The designer uploads the artwork / revision - completes the task. */
    public function complete(Request $request, DocumentWorkTask $task)
    {
        $user = $request->user();
        abort_unless(
            $task->assigned_to === $user->id || ($task->assigned_to === null && $task->isForTeamOf($user)) || $user->can('manageTasks', $task->document),
            403
        );

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:512000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $version = $this->tasks->complete($task, $request->file('file'), $user, $validated['notes'] ?? null);

        // Once the work is handed back, the job leaves the agency's view.
        if ($user->isAgencyUser()) {
            return redirect()->route('agency.index')
                ->with('status', "Uploaded as v{$version->version_no} and sent to the Brand Manager.");
        }

        return redirect()->route('documents.show', $task->document_id)
            ->with('status', "Uploaded as v{$version->version_no}. The Brand Manager has been notified to confirm it.");
    }

    public function destroy(Request $request, DocumentWorkTask $task)
    {
        $this->authorize('manageTasks', $task->document);
        abort_unless($task->status === 'open', 422);
        $this->tasks->cancel($task, $request->user());

        return back()->with('status', 'Work withdrawn from the ' . $task->teamLabel() . '.');
    }
}
