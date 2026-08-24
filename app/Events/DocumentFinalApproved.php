<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The workflow completed all the way through to approved_for_distribution -
 * the narrower, "fully cleared" case of WorkflowCompleted. Triggers the
 * pending distribution_records row - see
 * Listeners\Workflow\CreatePendingDistributionRecord. $actor is null when the
 * completing step was a skipped conditional stage (spec REQ-53) rather than a
 * human decision.
 */
class DocumentFinalApproved
{
    use Dispatchable;

    public function __construct(
        public Document $document,
        public DocumentWorkflowInstance $instance,
        public ?User $actor = null,
    ) {}
}
