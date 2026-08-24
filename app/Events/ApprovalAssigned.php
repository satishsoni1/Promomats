<?php

namespace App\Events;

use App\Models\DocumentStageAssignee;
use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One approver was just assigned a pending task at a stage. Fired once per
 * user - a parallel MLR stage assigning Medical+Regulatory+Legal at once
 * fires this three times, see WorkflowEngine::enterStage().
 */
class ApprovalAssigned
{
    use Dispatchable;

    public function __construct(
        public DocumentWorkflowInstance $instance,
        public WorkflowStage $stage,
        public User $user,
        public DocumentStageAssignee $task,
    ) {}
}
