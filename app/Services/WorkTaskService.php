<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkTask;
use App\Models\User;
use App\Notifications\DocumentActionNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Design Team work that sits outside the approval stages: artwork for a placeholder
 * job, and rework after "Approved with changes". Assigned to the whole team first
 * (the "umbrella"); anyone in it can take it or hand it to one designer. Uploading
 * the file completes the task and - for rework, if the owner asked for it - sends
 * the revision straight back into the workflow.
 */
class WorkTaskService
{
    public function __construct(
        protected DocumentVersionService $versions,
        protected WorkflowEngine $engine,
        protected AuditLogger $audit,
    ) {}

    public function requestArtwork(Document $document, User $requestedBy, ?string $instructions = null, ?Carbon $dueAt = null): DocumentWorkTask
    {
        return $this->create($document, 'artwork', $requestedBy, $instructions, $dueAt, resubmitOnUpload: false);
    }

    public function requestRework(Document $document, User $requestedBy, ?string $instructions, ?Carbon $dueAt, bool $resubmitOnUpload, ?int $assignTo = null): DocumentWorkTask
    {
        if ($document->status !== 'approved_with_changes_pending') {
            throw ValidationException::withMessages(['rework' => 'Rework can only be requested while the document is waiting for a revision.']);
        }

        $task = $this->create($document, 'rework', $requestedBy, $instructions, $dueAt, $resubmitOnUpload);

        if ($assignTo) {
            $this->assign($task, User::findOrFail($assignTo), $requestedBy);
        }

        return $task;
    }

    protected function create(Document $document, string $type, User $requestedBy, ?string $instructions, ?Carbon $dueAt, bool $resubmitOnUpload): DocumentWorkTask
    {
        if ($document->workTasks()->open()->exists()) {
            throw ValidationException::withMessages(['rework' => 'This document already has open work with the Design Team.']);
        }

        $task = DocumentWorkTask::create([
            'document_id' => $document->id,
            'type' => $type,
            'team' => 'design',
            'requested_by' => $requestedBy->id,
            'instructions' => $instructions,
            'resubmit_on_upload' => $resubmitOnUpload,
            'due_at' => $dueAt ?? now()->addHours(config('promomats.due_dates.default_stage_hours', 48)),
        ]);

        $this->audit->record(
            action: 'WORK_TASK_CREATED',
            document: $document,
            actor: $requestedBy,
            description: "{$task->typeLabel()} sent to the Design Team" . ($instructions ? ": {$instructions}" : '.'),
        );

        $this->notify(User::designTeam()->get(), $document, 'work_task_created', $requestedBy, $instructions);

        return $task;
    }

    /**
     * Hand the task to one designer (any Design Team member may do this, as may the
     * task owner or an admin).
     */
    public function assign(DocumentWorkTask $task, User $designer, User $actor): DocumentWorkTask
    {
        if ($task->status !== 'open') {
            throw ValidationException::withMessages(['assign' => 'This task is no longer open.']);
        }
        if (! $designer->is_active || ! $designer->isDesignTeam()) {
            throw ValidationException::withMessages(['assign' => "{$designer->name} isn't in the Design Team."]);
        }

        $task->update(['assigned_to' => $designer->id]);

        $this->audit->record(
            action: 'WORK_TASK_ASSIGNED',
            document: $task->document,
            actor: $actor,
            description: "{$task->typeLabel()} assigned to {$designer->name}.",
        );

        if ($designer->id !== $actor->id) {
            $this->notify(collect([$designer]), $task->document, 'work_task_assigned', $actor, $task->instructions);
        }

        return $task;
    }

    /**
     * The designer uploads the artwork/revision: stored as a normal new version, the
     * task is closed, the owner is told, and a rework the owner flagged for
     * automatic resubmission goes straight back into review.
     */
    public function complete(DocumentWorkTask $task, UploadedFile $file, User $designer, ?string $notes = null): DocumentVersion
    {
        return DB::transaction(function () use ($task, $file, $designer, $notes) {
            $task = DocumentWorkTask::lockForUpdate()->findOrFail($task->id);
            if ($task->status !== 'open') {
                throw ValidationException::withMessages(['file' => 'This task has already been completed.']);
            }

            $document = $task->document;
            $document->assertNotOnLegalHold();

            $version = $this->versions->storeNewVersion(
                document: $document,
                file: $file,
                uploader: $designer,
                changeNotes: $notes ?: ($task->type === 'rework' ? 'Rework by Design Team' : 'Artwork uploaded by Design Team'),
            );

            $task->update([
                'status' => 'completed',
                'assigned_to' => $task->assigned_to ?? $designer->id,
                'completed_version_id' => $version->id,
                'completed_by' => $designer->id,
                'completed_at' => now(),
            ]);

            if ($document->is_placeholder) {
                $document->update(['is_placeholder' => false]);
            }

            $this->audit->record(action: 'VERSION_CREATED', document: $document, version: $version, actor: $designer, description: "{$task->typeLabel()} completed.");

            $resubmitted = false;
            $instance = $document->activeWorkflowInstance;
            if ($task->type === 'rework' && $task->resubmit_on_upload && $instance && $document->status === 'approved_with_changes_pending') {
                $this->engine->resumeAfterRevision($instance, $version);
                $resubmitted = true;
            }

            $this->notify(
                User::whereIn('id', [$document->owner_id, $task->requested_by])->get(),
                $document,
                'work_task_completed',
                $designer,
                $resubmitted ? 'It has been sent back into review automatically.' : ($task->type === 'artwork' ? 'Submit it for review when ready.' : 'Resubmit it for review when ready.'),
            );

            return $version;
        });
    }

    public function cancel(DocumentWorkTask $task, User $actor): void
    {
        $task->update(['status' => 'cancelled']);
        $this->audit->record(action: 'WORK_TASK_CANCELLED', document: $task->document, actor: $actor, description: "{$task->typeLabel()} withdrawn from the Design Team.");
    }

    protected function notify($users, Document $document, string $event, ?User $actor, ?string $comments = null): void
    {
        foreach ($users->unique('id')->where('is_active', true) as $user) {
            if ($actor && $user->id === $actor->id) {
                continue;
            }
            $user->notify(new DocumentActionNotification(document: $document, event: $event, actor: $actor, comments: $comments));
        }
    }
}
