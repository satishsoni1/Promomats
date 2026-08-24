<?php

namespace App\Events;

use App\Models\DocumentStageAssignee;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A pending approval task has sat longer than its stage's SLA. Fired once per
 * task, the first time it crosses the threshold - see
 * Console\Commands\FlagOverdueApprovals, which also sends the reminder
 * notifications directly (that logic predates this event and stays put; this
 * event exists so the breach is captured in the audit trail as its own fact,
 * not just inferred from a notification having gone out).
 */
class SLABreached
{
    use Dispatchable;

    public function __construct(
        public DocumentStageAssignee $task,
    ) {}
}
