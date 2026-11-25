<?php

namespace App\Services;

use App\Models\ContentAgency;
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
 * Team work that sits outside the approval stages, for the internal Design Team or
 * the external Content Agency: the creative once the draft is aligned, artwork for a
 * placeholder job, and rework after "Not approved". Assigned to the whole team
 * first (the "umbrella"); anyone in it can take it or hand it to a colleague.
 * Uploading the file completes the task and comes back to the Brand Manager to
 * confirm - only Design Team rework the Brand Manager explicitly flagged goes
 * straight back into review.
 */
class WorkTaskService
{
    public function __construct(
        protected DocumentVersionService $versions,
        protected WorkflowEngine $engine,
        protected AuditLogger $audit,
    ) {}

    public function requestArtwork(Document $document, User $requestedBy, ?string $instructions = null, ?Carbon $dueAt = null, string $team = 'design', ?int $agencyId = null, ?int $assignTo = null): DocumentWorkTask
    {
        // Created and handed to the person together, or not at all.
        return DB::transaction(function () use ($document, $requestedBy, $instructions, $dueAt, $team, $agencyId, $assignTo) {
            $task = $this->create($document, 'artwork', $requestedBy, $instructions, $dueAt, resubmitOnUpload: false, team: $team, agencyId: $agencyId, assignTo: $assignTo);

            if ($assignTo) {
                $this->assign($task, User::findOrFail($assignTo), $requestedBy);
            }

            return $task;
        });
    }

    /**
     * The Brand Manager sends a job back to a team for rework (waiting on a revision)
     * or, once the Content Manager has aligned the draft, for the creative.
     */
    public function requestRework(Document $document, User $requestedBy, ?string $instructions, ?Carbon $dueAt, bool $resubmitOnUpload, ?int $assignTo = null, string $team = 'design', ?int $agencyId = null): DocumentWorkTask
    {
        $type = match ($document->status) {
            'approved_with_changes_pending' => 'rework',
            'draft_aligned' => 'creative',
            default => throw ValidationException::withMessages(['rework' => 'Work can only be assigned while the job is back with its Brand Manager.']),
        };

        // Agency uploads, and the creative, always come back to the Brand Manager to confirm.
        $resubmitOnUpload = $resubmitOnUpload && $type === 'rework' && $team === 'design';

        return DB::transaction(function () use ($document, $type, $requestedBy, $instructions, $dueAt, $resubmitOnUpload, $team, $agencyId, $assignTo) {
            $task = $this->create($document, $type, $requestedBy, $instructions, $dueAt, $resubmitOnUpload, $team, $agencyId, $assignTo);

            if ($assignTo) {
                $this->assign($task, User::findOrFail($assignTo), $requestedBy);
            }

            return $task;
        });
    }

    protected function create(Document $document, string $type, User $requestedBy, ?string $instructions, ?Carbon $dueAt, bool $resubmitOnUpload, string $team = 'design', ?int $agencyId = null, ?int $assignTo = null): DocumentWorkTask
    {
        if (! array_key_exists($team, DocumentWorkTask::TEAM_LABELS)) {
            throw ValidationException::withMessages(['rework' => 'Unknown team.']);
        }

        if ($document->workTasks()->open()->exists()) {
            throw ValidationException::withMessages(['rework' => 'This job already has open work with a team.']);
        }

        $agencyId = $team === 'agency' ? $this->resolveAgency($agencyId, $assignTo) : null;

        $task = DocumentWorkTask::create([
            'document_id' => $document->id,
            'type' => $type,
            'team' => $team,
            'content_agency_id' => $agencyId,
            // Set explicitly: assign() right after checks it, and the DB default
            // isn't on the in-memory model.
            'status' => 'open',
            'requested_by' => $requestedBy->id,
            'instructions' => $instructions,
            'resubmit_on_upload' => $resubmitOnUpload,
            'due_at' => $dueAt ?? now()->addHours(config('promomats.due_dates.default_stage_hours', 48)),
        ]);

        $this->audit->record(
            action: 'WORK_TASK_CREATED',
            document: $document,
            actor: $requestedBy,
            description: "{$task->typeLabel()} sent to the {$task->teamLabel()}" . ($instructions ? ": {$instructions}" : '.'),
        );

        // A task already handed to one person tells just them (assign() does that).
        if (! $assignTo) {
            $this->notify($task->teamMembers()->get(), $document, 'work_task_created', $requestedBy, $instructions);
        }

        return $task;
    }

    /**
     * Which content agency agency work goes to (MOM 24 Nov: the Brand Manager adds
     * a content agency to the workflow and picks a user from it): the one chosen,
     * else the chosen person's agency, else the only active agency. With no agency
     * master set up yet, work goes to every agency user as before.
     */
    protected function resolveAgency(?int $agencyId, ?int $assignTo): ?int
    {
        if ($agencyId) {
            if (! ContentAgency::active()->whereKey($agencyId)->exists()) {
                throw ValidationException::withMessages(['content_agency_id' => 'That content agency is not active.']);
            }

            return $agencyId;
        }

        if ($assignTo && ($fromUser = User::whereKey($assignTo)->value('content_agency_id'))) {
            return $fromUser;
        }

        $active = ContentAgency::active()->pluck('id');
        if ($active->count() === 1) {
            return $active->first();
        }
        if ($active->isEmpty()) {
            return null;
        }

        throw ValidationException::withMessages(['content_agency_id' => 'Choose which content agency this goes to.']);
    }

    /**
     * Hand the task to one person in its team (any team member may do this, as may
     * the Brand Manager or an admin).
     */
    public function assign(DocumentWorkTask $task, User $designer, User $actor): DocumentWorkTask
    {
        if ($task->status !== 'open') {
            throw ValidationException::withMessages(['assign' => 'This task is no longer open.']);
        }
        if (! $designer->is_active || ! $task->isForTeamOf($designer->loadMissing('roles'))) {
            throw ValidationException::withMessages(['assign' => "{$designer->name} isn't in the {$task->teamLabel()}."]);
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
                changeNotes: $notes ?: "{$task->typeLabel()} by the {$task->teamLabel()}",
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

            $this->audit->record(action: 'VERSION_CREATED', document: $document, version: $version, actor: $designer, description: "{$task->typeLabel()} completed by the {$task->teamLabel()}.");

            // Mid-review uploads carry on with the new file, as with any other upload.
            if ($document->activeWorkflowInstance && $document->status === 'in_review') {
                $this->engine->adoptNewVersion($document->activeWorkflowInstance, $version);
            }

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
                $resubmitted ? 'It has been sent back into review automatically.' : match ($task->type) {
                    'creative' => 'Check it, then start the review from the job page.',
                    'artwork' => 'Check it, then submit it for Content Manager alignment or review.',
                    default => 'Check it, then resubmit it - it goes back to the stage that sent it back.',
                },
            );

            return $version;
        });
    }

    public function cancel(DocumentWorkTask $task, User $actor): void
    {
        $task->update(['status' => 'cancelled']);
        $this->audit->record(action: 'WORK_TASK_CANCELLED', document: $task->document, actor: $actor, description: "{$task->typeLabel()} withdrawn from the {$task->teamLabel()}.");
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
