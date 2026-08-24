<?php

namespace App\Events;

use App\Models\DocumentVersion;
use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new version was uploaded and the workflow resumed after a revision loop.
 * See WorkflowEngine::resumeAfterRevision().
 */
class DocumentResubmitted
{
    use Dispatchable;

    public function __construct(
        public DocumentWorkflowInstance $instance,
        public DocumentVersion $newVersion,
        public User $actor,
    ) {}
}
