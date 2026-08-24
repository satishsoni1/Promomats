<?php

namespace App\Events;

use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reviewer recorded a decision at a stage - approved or not_approved. An
 * approved_with_changes decision fires RevisionRequested instead (not this
 * event too), since it's functionally a different outcome for anyone
 * listening. See WorkflowEngine::recordDecision().
 */
class ApprovalCompleted
{
    use Dispatchable;

    public function __construct(
        public DocumentWorkflowInstance $instance,
        public WorkflowStage $stage,
        public User $actor,
        public string $decision,
        public ?string $comments = null,
    ) {}
}
