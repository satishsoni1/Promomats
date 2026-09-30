<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentWorkTask;
use App\Models\User;
use App\Services\WorkTaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Design Team "umbrella" work: the task owner sends rework to the team, a team
 * member takes it or assigns it to a designer, and the designer uploads the file.
 */
class WorkTaskController extends Controller
{
    public function __construct(protected WorkTaskService $tasks) {}

    /** Task owner: "send this revision to the Design Team". */
    public function store(Request $request, Document $document)
    {
        $this->authorize('manageTasks', $document);

        $validated = $request->validate([
            'instructions' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'resubmit_on_upload' => ['nullable', 'boolean'],
            'assign_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $this->tasks->requestRework(
            document: $document,
            requestedBy: $request->user(),
            instructions: $validated['instructions'] ?? null,
            dueAt: ! empty($validated['due_date']) ? Carbon::parse($validated['due_date'])->endOfDay() : null,
            resubmitOnUpload: (bool) ($validated['resubmit_on_upload'] ?? false),
            assignTo: $validated['assign_to'] ?? null,
        );

        return back()->with('status', 'Sent to the Design Team for rework.');
    }

    /** A Design Team member takes the task themselves. */
    public function take(Request $request, DocumentWorkTask $task)
    {
        abort_unless($request->user()->isDesignTeam(), 403);
        $this->tasks->assign($task, $request->user(), $request->user());

        return back()->with('status', 'You have taken this task.');
    }

    /** Assign to a specific designer: any Design Team member, the task owner, or an admin. */
    public function assign(Request $request, DocumentWorkTask $task)
    {
        $user = $request->user();
        abort_unless($user->isDesignTeam() || $user->can('manageTasks', $task->document), 403);

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
            $task->assigned_to === $user->id || ($task->assigned_to === null && $user->isDesignTeam()) || $user->can('manageTasks', $task->document),
            403
        );

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:512000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $version = $this->tasks->complete($task, $request->file('file'), $user, $validated['notes'] ?? null);

        return redirect()->route('documents.show', $task->document_id)
            ->with('status', "Uploaded as v{$version->version_no}. The task owner has been notified.");
    }

    public function destroy(Request $request, DocumentWorkTask $task)
    {
        $this->authorize('manageTasks', $task->document);
        abort_unless($task->status === 'open', 422);
        $this->tasks->cancel($task, $request->user());

        return back()->with('status', 'Design work withdrawn.');
    }
}
