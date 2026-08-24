<?php

namespace App\Events;

use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reviewer recorded "approved with changes" - the document is headed back
 * for revision. See WorkflowEngine::recordDecision().
 */
class RevisionRequested
{
    use Dispatchable;

    public function __construct(
        public DocumentWorkflowInstance $instance,
        public WorkflowStage $stage,
        public User $actor,
        public ?string $comments = null,
    ) {}
}
