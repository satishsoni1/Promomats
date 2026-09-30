<?php

namespace App\Events;

use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stage (or a whole parallel group) resolved as Approved with changes / Not
 * Approved and the workflow is now parked with the task owner until a revision is
 * uploaded. See WorkflowEngine::resolveStage() 'return_to_owner'.
 */
class DocumentReturnedToOwner
{
    use Dispatchable;

    public function __construct(
        public DocumentWorkflowInstance $instance,
        public WorkflowStage $stage,
        public string $decision,
        public ?User $actor = null,
    ) {}
}
