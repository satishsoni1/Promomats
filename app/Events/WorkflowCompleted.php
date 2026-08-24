<?php

namespace App\Events;

use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A workflow instance reached a terminal state - approved, approved_with_changes
 * (fully approved, but not the final distribution stage), or rejected. See
 * WorkflowEngine::completeInstance(). $actor is whoever's decision resolved the
 * completing stage - null for a workflow that completed via a stage skip
 * (spec REQ-53) rather than a human decision.
 */
class WorkflowCompleted
{
    use Dispatchable;

    public function __construct(
        public DocumentWorkflowInstance $instance,
        public string $status,
        public ?User $actor = null,
        public ?string $previousDocumentStatus = null,
    ) {}
}
