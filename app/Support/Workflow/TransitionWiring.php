<?php

namespace App\Support\Workflow;

use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;

/**
 * The standard routing rules (Himalaya UAT round 2), in one place so the seeders
 * and the per-document workflow editor build identical flows:
 *
 *  - Approved              -> next stage (complete at the end).
 *  - Approved with changes -> next stage as well - the stage has approved it; the
 *                             Brand Manager sees the requested changes. Only from a
 *                             draft stage (draft loop) or the final stage does it go
 *                             back to the Brand Manager.
 *  - Not approved          -> back to the Brand Manager, who uploads the revision
 *                             (or has the Content Agency / Design Team do it); once
 *                             resubmitted it returns to the stage that rejected it.
 *
 * Only the first stage of each parallel group carries transitions - the engine
 * resolves a group through that representative.
 */
class TransitionWiring
{
    /**
     * @param  iterable<WorkflowStage>  $ordered  stages in sequence order
     */
    public static function wire(iterable $ordered): void
    {
        $stages = collect($ordered)->values();
        if ($stages->isEmpty()) {
            return;
        }

        WorkflowTransition::whereIn('workflow_stage_id', $stages->pluck('id'))->delete();

        $last = $stages->last();
        $seenGroups = [];

        foreach ($stages as $stage) {
            if ($stage->parallel_group) {
                if (isset($seenGroups[$stage->parallel_group])) {
                    continue;
                }
                $seenGroups[$stage->parallel_group] = true;
            }

            $isFinal = $stage->id === $last->id
                || ($stage->parallel_group && $stage->parallel_group === $last->parallel_group);

            WorkflowTransition::create([
                'workflow_stage_id' => $stage->id,
                'decision' => 'approved',
                'outcome_type' => $isFinal ? 'complete_approved' : 'next_stage',
            ]);

            $awcBack = $isFinal || $stage->isDraft();
            WorkflowTransition::create([
                'workflow_stage_id' => $stage->id,
                'decision' => 'approved_with_changes',
                'outcome_type' => $awcBack ? 'return_to_owner' : 'next_stage',
                'resume_at_stage_id' => $awcBack ? $stage->id : null,
            ]);

            WorkflowTransition::create([
                'workflow_stage_id' => $stage->id,
                'decision' => 'not_approved',
                'outcome_type' => 'return_to_owner',
                'resume_at_stage_id' => $stage->id,
            ]);
        }
    }
}
