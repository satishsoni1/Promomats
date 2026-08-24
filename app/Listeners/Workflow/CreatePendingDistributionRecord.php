<?php

namespace App\Listeners\Workflow;

use App\Events\DocumentFinalApproved;
use App\Models\DistributionRecord;

/**
 * Moved out of WorkflowEngine::completeInstance() so "create the tracked
 * distribution event" is a listener's responsibility, not the engine's -
 * the engine only decides *that* the workflow completed successfully; what
 * happens as a result of that is this class's job.
 */
class CreatePendingDistributionRecord
{
    public function handle(DocumentFinalApproved $event): void
    {
        DistributionRecord::create([
            'document_id' => $event->instance->document_id,
            'document_version_id' => $event->instance->document_version_id,
            'approved_by' => $event->actor?->id,
            'approved_at' => now(),
            'status' => 'pending',
        ]);
    }
}
