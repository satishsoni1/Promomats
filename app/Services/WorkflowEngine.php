<?php

namespace App\Services;

use App\Events\ApprovalAssigned;
use App\Events\ApprovalCompleted;
use App\Events\DocumentFinalApproved;
use App\Events\DocumentResubmitted;
use App\Events\DocumentSubmitted;
use App\Events\RevisionRequested;
use App\Events\WorkflowCompleted;
use App\Models\Document;
use App\Models\DocumentApprovalAction;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkflowInstance;
use App\Models\DocumentWorkflowStageRun;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The generic workflow engine (spec REQ-5/54): every decision here is driven
 * by the WorkflowTemplate/WorkflowStage/WorkflowTransition rows a document's
 * template resolves to - there is deliberately no per-document-type or
 * per-brand branching in this class. Side effects (audit logging,
 * notifications, the distribution record) are dispatched as events and
 * handled in app/Listeners/Workflow, not performed inline here - see
 * AppServiceProvider::boot() for how they're wired up (spec REQ-55).
 *
 * Stages normally run one at a time, but a WorkflowStage can share a
 * `parallel_group` with siblings (e.g. MLR Medical/Regulatory/Legal) - those
 * are entered together and the instance doesn't move on until *every* member
 * has resolved (fan-out/fan-in). document_workflow_stage_runs tracks each
 * stage's current-pass status for that purpose; document_stage_assignees
 * remains the per-person task list within a stage.
 */
class WorkflowEngine
{
    public function __construct(protected AuditLogger $audit = new AuditLogger()) {}

    /**
     * Kick off a workflow for a document version. Called on submit-for-review.
     */
    public function start(Document $document, DocumentVersion $version, User $initiator): DocumentWorkflowInstance
    {
        if (! $document->workflow_template_id) {
            throw ValidationException::withMessages([
                'workflow' => 'This document has no workflow template assigned.',
            ]);
        }

        return DB::transaction(function () use ($document, $version, $initiator) {
            $firstStage = $document->workflowTemplate->firstStage();

            if (! $firstStage) {
                throw ValidationException::withMessages([
                    'workflow' => 'The assigned workflow template has no stages configured.',
                ]);
            }

            $instance = DocumentWorkflowInstance::create([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'workflow_template_id' => $document->workflow_template_id,
                'current_stage_id' => $firstStage->id,
                'status' => 'running',
                'initiated_by' => $initiator->id,
                'started_at' => now(),
            ]);

            $document->update(['status' => 'in_review']);

            DocumentSubmitted::dispatch($document, $version, $instance, $initiator);

            $this->enterStage($instance, $firstStage);

            return $instance;
        });
    }

    /**
     * Open a stage (and, if it belongs to a parallel_group, every sibling in
     * that group at the same time): assign approvers as pending and notify
     * them. A stage whose condition doesn't match the document is skipped
     * (auto-resolved 'approved') without ever creating tasks for it; if that
     * empties out an entire group, the instance falls through to whatever
     * comes after the group, recursively.
     */
    protected function enterStage(DocumentWorkflowInstance $instance, WorkflowStage $stage): void
    {
        $group = $stage->groupSiblings();

        $instance->update(['current_stage_id' => $group->first()->id]);

        $anyOpened = false;

        foreach ($group as $memberStage) {
            // Spec REQ-53: a stage can be conditional on the document (brand,
            // document type, target audience, ...). One that doesn't apply
            // here is skipped entirely - no tasks, no approvers required.
            // This has no dedicated event in spec REQ-55's list, so it's
            // logged directly rather than through a listener.
            if (! $memberStage->conditionMatches($instance->document)) {
                $this->audit->record(
                    action: 'SKIPPED',
                    document: $instance->document,
                    instance: $instance,
                    stage: $memberStage,
                    description: "Stage \"{$memberStage->name}\" skipped - its condition didn't match this document.",
                );

                DocumentWorkflowStageRun::updateOrCreate(
                    ['document_workflow_instance_id' => $instance->id, 'workflow_stage_id' => $memberStage->id],
                    ['status' => 'resolved', 'decision' => 'approved', 'resolved_at' => now()]
                );

                continue;
            }

            $anyOpened = true;

            DocumentWorkflowStageRun::updateOrCreate(
                ['document_workflow_instance_id' => $instance->id, 'workflow_stage_id' => $memberStage->id],
                ['status' => 'open', 'decision' => null, 'entered_at' => now(), 'resolved_at' => null]
            );

            $userIds = collect($memberStage->approvers()->get())
                ->flatMap(fn ($approver) => $approver->resolveUserIds())
                ->unique()
                ->values();

            if ($userIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'workflow' => "Stage '{$memberStage->name}' has no resolvable approvers (check role assignments).",
                ]);
            }

            foreach ($userIds as $userId) {
                $task = DocumentStageAssignee::create([
                    'document_workflow_instance_id' => $instance->id,
                    'workflow_stage_id' => $memberStage->id,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'assigned_at' => now(),
                ]);

                ApprovalAssigned::dispatch($instance, $memberStage, User::find($userId), $task);
            }
        }

        if (! $anyOpened) {
            $next = $group->first()->nextStage();
            if ($next) {
                $this->enterStage($instance, $next);
            } else {
                $this->completeInstance($instance, 'approved');
            }
        }
    }

    /**
     * Record a user's decision (approved / approved_with_changes / not_approved).
     * The acting user's own pending task determines which stage they're acting
     * on - not a single instance-wide "current stage" - since a parallel_group
     * can leave several stages open for different people at once.
     */
    public function recordDecision(
        DocumentWorkflowInstance $instance,
        User $actor,
        string $decision,
        ?string $comments = null,
        ?string $ipAddress = null
    ): DocumentWorkflowInstance {
        abort_unless(in_array($decision, ['approved', 'approved_with_changes', 'not_approved']), 422, 'Invalid decision.');

        return DB::transaction(function () use ($instance, $actor, $decision, $comments, $ipAddress) {
            $assignee = $instance->pendingAssignees()
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if (! $assignee) {
                throw ValidationException::withMessages([
                    'approval' => 'You are not a pending approver for this document at its current stage.',
                ]);
            }

            $stage = WorkflowStage::lockForUpdate()->findOrFail($assignee->workflow_stage_id);

            // Permanent signed record - always written regardless of stage mode.
            // signed_name is the 21 CFR Part 11 signature manifestation (the
            // signer's printed name at the moment of signing, captured by the
            // caller only after re-authenticating the password - see
            // DocumentApprovalController::act()) - deliberately a point-in-time
            // snapshot, not a live join to the user's current name. This table
            // (not an event/listener) is the system of record for signatures;
            // ApprovalCompleted/RevisionRequested below are the *notification*
            // of that fact, dispatched after it's already durably saved.
            DocumentApprovalAction::create([
                'document_workflow_instance_id' => $instance->id,
                'document_id' => $instance->document_id,
                'document_version_id' => $instance->document_version_id,
                'workflow_stage_id' => $stage->id,
                'acted_by' => $actor->id,
                'signed_name' => $actor->name,
                'decision' => $decision,
                'comments' => $comments,
                'ip_address' => $ipAddress,
                'acted_at' => now(),
            ]);

            $assignee->update(['status' => 'acted', 'acted_at' => now()]);

            if ($decision === 'approved_with_changes') {
                RevisionRequested::dispatch($instance, $stage, $actor, $comments);
            } else {
                ApprovalCompleted::dispatch($instance, $stage, $actor, $decision, $comments);
            }

            $stageDecision = $this->resolveStageLocally($instance, $stage, $decision);

            if ($stageDecision !== null) {
                $this->advanceGroupIfComplete($instance, $stage, $actor);
            }

            return $instance->fresh();
        });
    }

    /**
     * Apply this stage's own approval_mode (any_one / majority / all_required)
     * to decide whether *this stage* has reached a verdict yet. Returns the
     * verdict once it has, or null while still waiting on other assignees at
     * this same stage. Once resolved, closes out every other still-pending
     * assignee at this stage as 'superseded' (they didn't need to act - the
     * stage already has its answer) and records the outcome on this run's
     * document_workflow_stage_runs row, so everyone can see who the stage was
     * actually decided by instead of a task sitting pending forever.
     */
    protected function resolveStageLocally(DocumentWorkflowInstance $instance, WorkflowStage $stage, string $decision): ?string
    {
        if ($stage->approval_mode === 'all_required') {
            $stillPending = $instance->pendingAssignees()->where('workflow_stage_id', $stage->id)->exists();

            // If someone already gave a non-approved decision, resolve early using
            // that decision; otherwise wait until everyone has acted.
            $finalDecision = ($decision !== 'approved' || ! $stillPending) ? $decision : null;
        } elseif ($stage->approval_mode === 'majority') {
            $finalDecision = $this->computeMajorityOutcome($instance, $stage);
        } else {
            // any_one: first decision resolves the stage immediately.
            $finalDecision = $decision;
        }

        if ($finalDecision === null) {
            return null;
        }

        $instance->pendingAssignees()->where('workflow_stage_id', $stage->id)->update(['status' => 'superseded']);

        DocumentWorkflowStageRun::updateOrCreate(
            ['document_workflow_instance_id' => $instance->id, 'workflow_stage_id' => $stage->id],
            ['status' => 'resolved', 'decision' => $finalDecision, 'resolved_at' => now()]
        );

        return $finalDecision;
    }

    /**
     * Quorum voting: resolves as soon as enough "approved" decisions are in
     * (requiredQuorum() - a simple majority by default, or an explicit
     * quorum_count), or as soon as approval becomes mathematically unreachable
     * given how many approvers are still pending. Returns null while the
     * outcome is still genuinely undecided (same as an all_required stage that
     * isn't fully resolved yet).
     */
    protected function computeMajorityOutcome(DocumentWorkflowInstance $instance, WorkflowStage $stage): ?string
    {
        $totalApprovers = collect($stage->approvers()->get())
            ->flatMap(fn ($approver) => $approver->resolveUserIds())
            ->unique()
            ->count();

        $quorum = $stage->requiredQuorum($totalApprovers);

        $actionsAtStage = $instance->actions()->where('workflow_stage_id', $stage->id);
        $approvedCount = (clone $actionsAtStage)->where('decision', 'approved')->count();
        $stillPending = $instance->pendingAssignees()->where('workflow_stage_id', $stage->id)->count();

        if ($approvedCount >= $quorum) {
            return 'approved';
        }

        if ($approvedCount + $stillPending >= $quorum) {
            return null; // quorum is still reachable - wait for the remaining approvers
        }

        // Quorum can no longer be reached even if every pending approver were to
        // approve. Resolve using whichever non-approved decision was more common
        // among those who did act, defaulting to the more conservative
        // not_approved on a tie.
        $revisionCount = (clone $actionsAtStage)->where('decision', 'approved_with_changes')->count();
        $rejectedCount = (clone $actionsAtStage)->where('decision', 'not_approved')->count();

        return $revisionCount > $rejectedCount ? 'approved_with_changes' : 'not_approved';
    }

    /**
     * Once a stage has resolved, check whether every other member of its
     * parallel_group (or just itself, for a solo stage) has also resolved. If
     * any sibling is still open, do nothing - the group isn't done yet. Once
     * the whole group is in, fan the individual verdicts into one outcome and
     * apply the configured transition using the group's first stage as the
     * representative (that's the only stage a template needs transition rows
     * on for a group).
     */
    protected function advanceGroupIfComplete(DocumentWorkflowInstance $instance, WorkflowStage $stage, ?User $actor): void
    {
        $group = $stage->groupSiblings();

        $stillOpen = $instance->stageRuns()
            ->whereIn('workflow_stage_id', $group->pluck('id'))
            ->where('status', 'open')
            ->exists();

        if ($stillOpen) {
            return;
        }

        $decision = $this->aggregateGroupDecision($instance, $group);

        $this->resolveStage($instance, $group->first(), $decision, $actor);
    }

    /**
     * Most-conservative-wins fan-in: if any track in the group came back
     * not_approved, the group as a whole is not_approved; otherwise if any
     * track asked for changes, the group asked for changes; only if every
     * track cleanly approved does the group approve.
     */
    protected function aggregateGroupDecision(DocumentWorkflowInstance $instance, Collection $group): string
    {
        $decisions = $instance->stageRuns()
            ->whereIn('workflow_stage_id', $group->pluck('id'))
            ->pluck('decision');

        if ($decisions->contains('not_approved')) {
            return 'not_approved';
        }

        if ($decisions->contains('approved_with_changes')) {
            return 'approved_with_changes';
        }

        return 'approved';
    }

    /**
     * Apply the configured transition rule for this stage + decision.
     */
    protected function resolveStage(DocumentWorkflowInstance $instance, WorkflowStage $stage, string $decision, ?User $actor = null): void
    {
        $transition = $stage->transitionFor($decision);

        if (! $transition) {
            throw ValidationException::withMessages([
                'workflow' => "No transition rule configured for stage '{$stage->name}' on decision '{$decision}'.",
            ]);
        }

        switch ($transition->outcome_type) {
            case 'next_stage':
                $target = $transition->targetStage ?? $stage->nextStage();
                if ($target) {
                    $this->enterStage($instance, $target);
                } else {
                    $this->completeInstance($instance, 'approved', $actor);
                }
                break;

            case 'return_to_owner':
                // AwC/NA: park the document and let the document owner fix it -
                // no stage is re-entered here. resume_at_stage_id records where
                // review picks back up (the stage, or the whole parallel group,
                // that flagged it) once resumeAfterRevision() runs after a new
                // version is submitted.
                $instance->update(['resume_at_stage_id' => $transition->resume_at_stage_id ?? $stage->id]);
                $instance->document->update(['status' => 'approved_with_changes_pending']);
                break;

            case 'return_to_stage':
                // Legacy "revision hub" behaviour (still used by templates that
                // haven't opted into return_to_owner): jump straight back into
                // another gate for a fresh pass, remembering where to resume once
                // that clears.
                $instance->update(['resume_at_stage_id' => $transition->resume_at_stage_id ?? $stage->id]);
                $instance->document->update(['status' => 'approved_with_changes_pending']);
                $this->enterStage($instance, $transition->targetStage);
                break;

            case 'terminate_rejected':
                $this->completeInstance($instance, 'rejected', $actor);
                break;

            case 'complete_approved':
                $this->completeInstance($instance, $decision === 'approved_with_changes' ? 'approved_with_changes' : 'approved', $actor);
                break;
        }
    }

    /**
     * Called when a revised version is re-submitted after an AwC/NA loop.
     * Resumes at the stage (or parallel group) recorded on the instance, or
     * restarts from the beginning if none set.
     */
    public function resumeAfterRevision(DocumentWorkflowInstance $instance, DocumentVersion $newVersion): void
    {
        DB::transaction(function () use ($instance, $newVersion) {
            $instance->update([
                'document_version_id' => $newVersion->id,
                'status' => 'running',
            ]);

            $resumeStage = $instance->resumeAtStage ?? $instance->document->workflowTemplate->firstStage();

            $instance->update(['resume_at_stage_id' => null]);
            $instance->document->update(['status' => 'in_review']);

            DocumentResubmitted::dispatch($instance, $newVersion, $newVersion->uploader);

            $this->enterStage($instance, $resumeStage);
        });
    }

    protected function completeInstance(DocumentWorkflowInstance $instance, string $status, ?User $actor = null): void
    {
        $previousDocumentStatus = $instance->document->status;

        $instance->update([
            'status' => $status,
            'completed_at' => now(),
            'current_stage_id' => null,
        ]);

        $documentStatus = match ($status) {
            'approved' => 'approved_for_distribution',
            'approved_with_changes' => 'approved',
            'rejected' => 'rejected',
            default => 'in_review',
        };

        $instance->document->update(['status' => $documentStatus]);

        WorkflowCompleted::dispatch($instance, $status, $actor, $previousDocumentStatus);

        if ($documentStatus === 'approved_for_distribution') {
            DocumentFinalApproved::dispatch($instance->document, $instance, $actor);
        }
    }
}
