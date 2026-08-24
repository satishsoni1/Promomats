<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A document entered a workflow for the first time - see
 * WorkflowEngine::start(). Not fired again on resubmission after a revision;
 * that's DocumentResubmitted.
 */
class DocumentSubmitted
{
    use Dispatchable;

    public function __construct(
        public Document $document,
        public DocumentVersion $version,
        public DocumentWorkflowInstance $instance,
        public User $initiator,
    ) {}
}
