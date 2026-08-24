<?php

namespace App\Policies;

use App\Models\DocumentStageAssignee;
use App\Models\User;

class ApprovalTaskPolicy
{
    /**
     * Only the assigned approver may view their own pending task in detail (the
     * inbox list itself is already scoped to `$user->pendingApprovals()`, so
     * this guards a direct/deep link to someone else's task).
     */
    public function view(User $user, DocumentStageAssignee $task): bool
    {
        return $task->user_id === $user->id || $user->can('access-admin');
    }

    /**
     * The actual approve/reject/request-revision action. WorkflowEngine::
     * recordDecision() independently re-checks "is this user a pending
     * approver at the document's current stage" inside its DB transaction
     * (with the stage row locked) before recording anything - that check is
     * the one that actually can't be bypassed. This policy mirrors the same
     * rule so any future entry point (a REST API, a bulk-approve action) gets
     * the identical guarantee without having to know the engine's internals.
     */
    public function act(User $user, DocumentStageAssignee $task): bool
    {
        return $task->user_id === $user->id && $task->status === 'pending';
    }
}
