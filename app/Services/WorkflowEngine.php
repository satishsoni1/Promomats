<?php

namespace App\Services;

use App\Events\ApprovalAssigned;
use App\Events\ApprovalCompleted;
use App\Events\DocumentFinalApproved;
use App\Events\DocumentResubmitted;
use App\Events\DocumentReturnedToOwner;
use App\Events\DocumentSubmitted;
use App\Events\RevisionRequested;
use App\Events\WorkflowCompleted;
use App\Models\Document;
use App\Models\DocumentApprovalAction;
use App\Models\DocumentComment;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkflowInstance;
use App\Models\DocumentWorkflowStageRun;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\DocumentActionNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Workflow\StageSchedule;
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

            // Stages with nobody selected are skipped - make sure at least one isn't.
            $anyStageRuns = $document->workflowTemplate->stages()->get()->contains(
                fn (WorkflowStage $s) => ! $this->isStageEmpty($document, $s) && $s->conditionMatches($document)
            );
            if (! $anyStageRuns) {
                throw ValidationException::withMessages([
                    'workflow' => 'No stage of this workflow has a stakeholder to review it - pick at least one stakeholder.',
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

        // A stage opening means someone is reviewing it now - never leave the
        // document showing "Revise & Resubmit" while a reviewer actually has it.
        if ($instance->document->status === 'approved_with_changes_pending') {
            $instance->document->update(['status' => 'in_review']);
        }

        $anyOpened = false;

        foreach ($group as $memberStage) {
            // MOM 24 Nov: a stage nobody was selected for is skipped and hidden from
            // the approval matrix. Once the Brand Manager has picked stakeholders,
            // every stage they left empty is skipped (it used to be only MLR tracks);
            // a stage whose role nobody eligible holds is skipped too, rather than
            // stopping the workflow.
            if ($this->isStageEmpty($instance->document, $memberStage)) {
                $this->audit->record(
                    action: 'SKIPPED',
                    document: $instance->document,
                    instance: $instance,
                    stage: $memberStage,
                    description: "Stage \"{$memberStage->name}\" skipped - no stakeholder was selected for it.",
                );

                DocumentWorkflowStageRun::updateOrCreate(
                    ['document_workflow_instance_id' => $instance->id, 'workflow_stage_id' => $memberStage->id],
                    ['status' => 'resolved', 'decision' => 'approved', 'resolved_at' => now()]
                );

                continue;
            }

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

            $userIds = $this->resolveStageApprovers($instance->document, $memberStage);

            // The Brand Manager may have set an exact due date/time for this stage.
            $dueAt = $instance->document->dueAtForStage($memberStage);

            foreach ($userIds as $userId) {
                $task = DocumentStageAssignee::create([
                    'document_workflow_instance_id' => $instance->id,
                    'workflow_stage_id' => $memberStage->id,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'assigned_at' => now(),
                    'due_at' => $dueAt,
                ]);

                ApprovalAssigned::dispatch($instance, $memberStage, User::find($userId), $task);
            }
        }

        if (! $anyOpened) {
            $next = $group->first()->nextStage();
            if ($next) {
                $this->enterStage($instance, $next);
            } elseif ($group->contains(fn (WorkflowStage $s) => $s->isChairperson())) {
                $this->awaitClosure($instance, 'approved');
            } else {
                $this->completeInstance($instance, 'approved');
            }

            return;
        }

        // The overall due date follows the stages actually in play.
        app(StageSchedule::class)->refreshDueDate($instance->document->fresh());
    }

    /**
     * Whether nobody will be asked to take this stage on this document: the Brand
     * Manager picked stakeholders but none for this stage, or nobody eligible holds
     * its role(s). Such a stage is skipped and hidden from the approval matrix.
     */
    public function isStageEmpty(Document $document, WorkflowStage $stage): bool
    {
        if ($document->effectivePicksForStage($stage) === null && $document->hasStakeholderPicks()) {
            return true;
        }

        return $this->resolveStageApprovers($document, $stage)->isEmpty();
    }

    /**
     * The concrete approver user IDs for a stage on a specific document: the
     * document owner's per-stage picks if they made any at upload time (see
     * DocumentStageApproverSelection), otherwise the stage's own role/user
     * approver rules resolved live. Every pick was already validated against the
     * stage's candidate pool when it was saved, so this trusts it as-is.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected function resolveStageApprovers(Document $document, WorkflowStage $stage): Collection
    {
        $picked = $document->effectivePicksForStage($stage);

        $ids = $picked ?? collect($stage->approvers()->get())
            ->flatMap(fn ($approver) => $approver->resolveUserIds())
            ->unique()
            ->values();

        // Brand Managers start jobs and assign stakeholders but never approve or
        // reject, and nobody checks their own job ("makers can't be checkers") - so
        // neither is ever handed a decision task.
        return User::with('roles')->whereIn('id', $ids)->get()
            ->reject(fn (User $u) => $u->isBrandManagerOnly() || $u->id === $document->owner_id)
            ->pluck('id')
            ->values();
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
            // Serialize decisions on the same instance. Without this, two reviewers in
            // a parallel group approving at the same moment each saw the other's track
            // still "open" and neither advanced the group - the workflow stalled.
            // Must be the first read in the transaction so InnoDB's snapshot is taken
            // after the lock is acquired (i.e. after the other decision committed).
            $instance = DocumentWorkflowInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();

            $assignee = $instance->pendingAssignees()
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if (! $assignee) {
                throw ValidationException::withMessages([
                    'approval' => 'You are not a pending approver for this document at its current stage.',
                ]);
            }

            if (! $actor->canRecordDecisions()) {
                throw ValidationException::withMessages([
                    'approval' => 'Brand Managers can start, upload and assign tasks, but cannot approve or reject them.',
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

            // Submitting the review publishes this reviewer's draft comments.
            DocumentComment::publishDraftsBy($instance->document_id, [$actor->id]);

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

        $superseded = $instance->pendingAssignees()->where('workflow_stage_id', $stage->id);
        // The stage has moved on, so anything its other reviewers drafted is now visible too.
        DocumentComment::publishDraftsBy($instance->document_id, (clone $superseded)->pluck('user_id'));
        $superseded->update(['status' => 'superseded']);

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
        $totalApprovers = $this->resolveStageApprovers($instance->document, $stage)->count();

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
            ->lockForUpdate() // locking read: sees the latest committed sibling runs, not a stale snapshot
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

        // MOM 24 Nov: after the Chairperson, the Brand Manager gives the final approval
        // and closes the job (after "Approved with changes" they can upload a new
        // version first) - it is not Approved for Production until they do.
        if ($stage->isChairperson() && in_array($decision, ['approved', 'approved_with_changes'], true)) {
            $this->awaitClosure($instance, $decision, $actor);

            return;
        }

        // A draft stage signed off: the job goes back to the Brand Manager, who
        // commissions the creative (Content Agency / Design Team) and triggers the
        // next step themselves - nothing moves on automatically (UAT feedback:
        // "any trigger in workflow should be initiated by the Brand Manager").
        if ($stage->isDraft() && $decision === 'approved' && $transition->outcome_type === 'next_stage') {
            $next = $transition->targetStage ?? $stage->nextStage();
            if ($next) {
                $instance->update(['resume_at_stage_id' => $next->id, 'current_stage_id' => $stage->id]);
                $instance->document->update(['status' => 'draft_aligned']);
                DocumentReturnedToOwner::dispatch($instance, $stage, 'draft_aligned', $actor);

                return;
            }
        }

        switch ($transition->outcome_type) {
            case 'next_stage':
                // Leaving a legacy "revision hub" after it cleared a revision: pick
                // review back up where it was flagged, rather than walking every
                // stage from the hub onwards again.
                if ($stage->is_revision_stage && $instance->resume_at_stage_id && $instance->resume_at_stage_id !== $stage->id) {
                    $target = $instance->resumeAtStage;
                    $instance->update(['resume_at_stage_id' => null]);
                } else {
                    $target = $transition->targetStage ?? $stage->nextStage();
                }
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
                $resumeAt = $transition->resume_at_stage_id ?? $stage->id;
                // MOM 24 Nov: rejected at any stage after TM - once the new version is
                // in, it goes back to TM and through every later stage again.
                if ($decision === 'not_approved' && ($tm = $this->rereviewStageFor($stage))) {
                    $resumeAt = $tm->id;
                }
                $instance->update(['resume_at_stage_id' => $resumeAt]);
                $instance->document->update(['status' => 'approved_with_changes_pending']);
                DocumentReturnedToOwner::dispatch($instance, $stage, $decision, $actor);
                break;

            case 'return_to_stage':
                // Legacy "revision hub" behaviour (still used by templates that
                // haven't opted into return_to_owner): jump straight back into
                // another gate for a fresh pass, remembering where to resume once
                // that clears.
                $resumeAt = $transition->resume_at_stage_id ?? $stage->id;
                // Rejected after TM/AGM: once the hub clears, review restarts at TM/AGM.
                if ($decision === 'not_approved' && ($tm = $this->rereviewStageFor($stage))) {
                    $resumeAt = $tm->id;
                }
                $instance->update(['resume_at_stage_id' => $resumeAt]);
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
     * The TM stage a rejection at $stage sends the revision back through, or null
     * when $stage is TM itself or comes before it.
     */
    protected function rereviewStageFor(WorkflowStage $stage): ?WorkflowStage
    {
        $tm = $stage->template->stages()
            ->whereIn('code', config('promomats.workflow.rereview_from_stage_codes', ['TM_AGM']))
            ->orderBy('sequence_no')
            ->first();

        return $tm && $stage->sequence_no > $tm->sequence_no ? $tm : null;
    }

    /**
     * The Chairperson has signed off: the workflow's reviews are over, and the job
     * waits on its Brand Manager's final approval and closure (see close()).
     */
    protected function awaitClosure(DocumentWorkflowInstance $instance, string $decision, ?User $actor = null): void
    {
        $instance->update([
            'status' => $decision === 'approved_with_changes' ? 'approved_with_changes' : 'approved',
            'completed_at' => now(),
            'current_stage_id' => null,
        ]);
        $instance->document->update(['status' => 'pending_closure']);

        $this->audit->record(
            action: 'AWAITING_CLOSURE',
            document: $instance->document,
            instance: $instance,
            actor: $actor,
            description: $decision === 'approved_with_changes'
                ? 'Chairperson approved with changes - with the Brand Manager to upload a new version (optional) and close.'
                : 'Chairperson approved - with the Brand Manager for final approval and closure.',
        );

        $owner = $instance->document->owner;
        if ($owner?->is_active) {
            $owner->notify(new DocumentActionNotification(
                document: $instance->document,
                event: 'closure_pending',
                actor: $actor,
                decision: $decision,
                comments: $instance->actions()->latest('acted_at')->value('comments'),
            ));
        }
    }

    /**
     * The Brand Manager's final approval and closure after the Chairperson. The job
     * becomes Approved for Production (the template's completion status); from
     * there the Brand Manager submits it for distribution.
     */
    public function close(Document $document, User $actor, ?DocumentVersion $newVersion = null): void
    {
        DB::transaction(function () use ($document, $actor, $newVersion) {
            $document = Document::lockForUpdate()->findOrFail($document->id);
            if ($document->status !== 'pending_closure') {
                throw ValidationException::withMessages(['closure' => 'This job is not waiting to be closed.']);
            }

            $instance = $document->workflowInstances()->latest('id')->firstOrFail();
            if ($newVersion) {
                $instance->update(['document_version_id' => $newVersion->id]);
            }

            $status = $instance->template?->completion_status ?: 'approved_for_production';
            $document->update(['status' => $status]);

            $this->audit->record(
                action: 'DOCUMENT_CLOSED',
                document: $document,
                instance: $instance,
                actor: $actor,
                oldStatus: 'pending_closure',
                newStatus: $status,
                description: "{$actor->name} gave the final approval and closed the job" . ($newVersion ? " with v{$newVersion->version_no}" : '') . '.',
            );

            WorkflowCompleted::dispatch($instance, $instance->status, $actor, 'pending_closure');

            if ($status === 'approved_for_distribution') {
                DocumentFinalApproved::dispatch($document, $instance, $actor);
            }
        });
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

            // "Approved with changes" means that stage already approved it: once the
            // revision is in, move on to the next stage (or finish, after the last
            // one) instead of sending it back to the same reviewers (UAT feedback).
            // Not Approved still goes back to the stage that rejected it.
            // Only when that stage is the one that sent it back: after a rejection at
            // a later stage the job resumes at TM/AGM, which must review it again even
            // if it had approved with changes earlier.
            $lastStageId = $instance->actions()->latest('acted_at')->latest('id')->value('workflow_stage_id');
            if (! $resumeStage->isDraft()
                && $resumeStage->groupSiblings()->contains('id', $lastStageId)
                && $this->wasApprovedWithChanges($instance, $resumeStage)
                && ($instance->document->workflowTemplate->awc_resume ?? 'next_stage') === 'next_stage') {
                $next = $resumeStage->nextStage();
                $next ? $this->enterStage($instance, $next) : $this->completeInstance($instance, 'approved', $newVersion->uploader);

                return;
            }

            $this->enterStage($instance, $resumeStage);
        });
    }

    /**
     * The Brand Manager triggers the review after the Content Manager aligned the
     * draft (status draft_aligned) - usually once the Content Agency / Design Team
     * has uploaded the creative. Picks up at the stage after the draft stage.
     */
    public function continueAfterDraft(DocumentWorkflowInstance $instance, DocumentVersion $version, User $actor): void
    {
        DB::transaction(function () use ($instance, $version, $actor) {
            $document = $instance->document;
            $next = $instance->resumeAtStage;

            if ($document->status !== 'draft_aligned' || ! $next) {
                throw ValidationException::withMessages(['workflow' => 'This job is not waiting to start its review.']);
            }

            $instance->update(['document_version_id' => $version->id, 'resume_at_stage_id' => null]);
            $document->update(['status' => 'in_review']);

            $this->audit->record(
                action: 'WORKFLOW_CONTINUED',
                document: $document,
                instance: $instance,
                stage: $next,
                actor: $actor,
                description: "{$actor->name} sent v{$version->version_no} on to \"{$next->name}\" after the draft was aligned.",
            );

            $this->enterStage($instance, $next);
        });
    }

    /**
     * Whether the stage (or parallel group) review is resuming at last resolved as
     * "Approved with changes" - the group's fanned-in verdict, same rule as
     * aggregateGroupDecision().
     */
    protected function wasApprovedWithChanges(DocumentWorkflowInstance $instance, WorkflowStage $stage): bool
    {
        return $this->aggregateGroupDecision($instance, $stage->groupSiblings()) === 'approved_with_changes';
    }

    /**
     * Hand a pending task to someone else - e.g. the original reviewer is on leave.
     * The old task is closed as 'reassigned' (kept for the audit trail) and a new
     * one opened for $newUser, due 48 hours (the stage's due period) after it's
     * assigned to them.
     */
    public function reassignTask(DocumentStageAssignee $task, User $newUser, User $actor, ?string $reason = null): DocumentStageAssignee
    {
        return DB::transaction(function () use ($task, $newUser, $actor, $reason) {
            $task = DocumentStageAssignee::lockForUpdate()->findOrFail($task->id);

            if ($task->status !== 'pending') {
                throw ValidationException::withMessages(['reassign' => 'Only a pending task can be reassigned.']);
            }
            if ($newUser->id === $task->user_id) {
                throw ValidationException::withMessages(['reassign' => 'That person already has this task.']);
            }
            if ($newUser->id === $task->instance->document->owner_id) {
                throw ValidationException::withMessages(['reassign' => "{$newUser->name} owns this job - makers can't be checkers."]);
            }
            if (! $newUser->is_active || ! $newUser->canRecordDecisions()) {
                throw ValidationException::withMessages(['reassign' => "{$newUser->name} can't take approval tasks."]);
            }
            if ($task->instance->pendingAssignees()->where('workflow_stage_id', $task->workflow_stage_id)->where('user_id', $newUser->id)->exists()) {
                throw ValidationException::withMessages(['reassign' => "{$newUser->name} already has a task at this stage."]);
            }

            $task->update(['status' => 'reassigned', 'acted_at' => now(), 'reassigned_by' => $actor->id, 'reassign_reason' => $reason]);

            $new = DocumentStageAssignee::create([
                'document_workflow_instance_id' => $task->document_workflow_instance_id,
                'workflow_stage_id' => $task->workflow_stage_id,
                'user_id' => $newUser->id,
                'reassigned_from_id' => $task->id,
                'reassigned_by' => $actor->id,
                'reassign_reason' => $reason,
                'status' => 'pending',
                'assigned_at' => now(),
                // 48 hours (the stage's due period) from the time it's assigned to them -
                // unless the Brand Manager set an exact due date/time for the stage.
                'due_at' => $task->instance->document->dueAtForStage($task->stage),
            ]);

            $this->audit->record(
                action: 'TASK_REASSIGNED',
                document: $task->instance->document,
                instance: $task->instance,
                stage: $task->stage,
                actor: $actor,
                description: '"' . $task->stage->name . '" reassigned from ' . $task->user?->name . ' to ' . $newUser->name . ($reason ? " - {$reason}" : '.'),
            );

            ApprovalAssigned::dispatch($task->instance, $task->stage, $newUser, $new);

            return $new;
        });
    }

    /**
     * A new version was uploaded while the document is mid-review (allowed at every
     * stage): reviewers from here on act on it.
     */
    public function adoptNewVersion(DocumentWorkflowInstance $instance, DocumentVersion $version): void
    {
        if ($instance->status === 'running') {
            $instance->update(['document_version_id' => $version->id]);
        }
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
            // Most flows end "Approved for Distribution"; the Chairperson flows end
            // "Approved for Production" - reachable only through that sign-off.
            'approved' => $instance->template?->completion_status ?: 'approved_for_distribution',
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
