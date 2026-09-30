<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentStageAssignee;
use App\Models\User;
use App\Notifications\DocumentActionNotification;
use App\Services\Audit\AuditLogger;
use App\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The task owner managing live approval tasks: hand one to another stakeholder
 * when the assignee is unavailable, or move its due date.
 */
class DocumentTaskController extends Controller
{
    public function __construct(protected WorkflowEngine $engine, protected AuditLogger $audit) {}

    public function reassign(Request $request, Document $document, DocumentStageAssignee $task)
    {
        $this->authorize('manageTasks', $document);
        abort_unless($task->instance?->document_id === $document->id, 404);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $newUser = User::with('roles')->findOrFail($validated['user_id']);
        $this->engine->reassignTask($task, $newUser, $request->user(), $validated['reason'] ?? null);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', "\"{$task->stage->name}\" reassigned to {$newUser->name}.");
    }

    public function updateDue(Request $request, Document $document, DocumentStageAssignee $task)
    {
        $this->authorize('manageTasks', $document);
        abort_unless($task->instance?->document_id === $document->id && $task->status === 'pending', 404);

        $validated = $request->validate(['due_at' => ['required', 'date', 'after:now']]);

        $old = $task->dueAt();
        $task->update([
            'due_at' => Carbon::parse($validated['due_at']),
            'due_soon_notified_at' => null,
            'overdue_notified_at' => null,
        ]);

        $this->audit->record(
            action: 'DUE_DATE_CHANGED',
            document: $document,
            instance: $task->instance,
            stage: $task->stage,
            actor: $request->user(),
            description: "Due date for {$task->user?->name} at \"{$task->stage->name}\" moved from " . ($old?->format('d M Y H:i') ?? '—') . ' to ' . $task->due_at->format('d M Y H:i') . '.',
        );

        if ($task->user && $task->user_id !== $request->user()->id) {
            $task->user->notify(new DocumentActionNotification(
                document: $document,
                event: 'stage_assigned',
                stage: $task->stage,
                actor: $request->user(),
                comments: 'Due date updated to ' . $task->due_at->format('d M Y, H:i') . '.',
            ));
        }

        return back()->with('status', 'Due date updated.');
    }
}
