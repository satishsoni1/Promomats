<?php

namespace Database\Seeders\Concerns;

use App\Models\Role;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;

/**
 * Shared helper for building a template's stages + A/AwC/NA transition rules from a
 * flat stage list, used by all the Pharma/Himalaya workflow seeders.
 *
 * Two revision models are supported, chosen per template via $reviseWithOwner:
 *
 * - Default (false) - the "revision hub" model used by the Pharma Workflow seeders:
 *   any stage's AwC/NA jumps straight back into an earlier gate (revisionHubCode) for
 *   a fresh pass; once that gate clears, review resumes at the stage that flagged it.
 * - $reviseWithOwner=true - AwC/NA simply parks the document
 *   (status=approved_with_changes_pending) and notifies the document owner to revise
 *   and upload a new version; no gate is re-entered until the owner resubmits. Not
 *   Approved at the very first stage is still a hard stop either way.
 *
 * A stage entry can also carry 'parallel_group' => 'SOME_KEY': every stage sharing
 * that key (within the same template) is entered together and the instance won't
 * move on until all of them have resolved - see WorkflowStage::groupSiblings() and
 * WorkflowEngine's fan-out/fan-in. Only the first (lowest sequence_no) stage in a
 * group gets transition rows; its siblings' outcomes are driven entirely by it.
 */
trait WiresLinearApprovalChain
{
    /**
     * @param  array<int, array{seq:int, name:string, code:string, role:string, mode?:string, final?:bool, parallel_group?:string}>  $stages
     */
    protected function wireStages(WorkflowTemplate $template, array $stages, string $revisionHubCode, bool $hubNaTerminates = true, bool $reviseWithOwner = false): void
    {
        $roleId = fn (string $name) => Role::where('name', $name)->value('id');

        $created = [];
        foreach ($stages as $s) {
            $stage = $this->createStage($template, $s, $revisionHubCode);

            if ($rid = $roleId($s['role'])) {
                $stage->approvers()->create(['role_id' => $rid]);
            }

            $created[$s['code']] = $stage;
        }

        $reviseWithOwner
            ? $this->wireTransitionsReviseWithOwner($created)
            : $this->wireTransitions($created, $revisionHubCode, $hubNaTerminates);
    }

    /**
     * Same shape as wireStages(), but for templates wired to specific named people
     * rather than roles - each stage names one or more user IDs directly ("either of
     * these people may approve"), instead of "anyone holding this role". Used for
     * workflows sourced from a brief that names individuals per stage rather than
     * job functions.
     *
     * @param  array<int, array{seq:int, name:string, code:string, user_ids:int[], mode?:string, final?:bool, parallel_group?:string}>  $stages
     */
    protected function wireStagesWithUsers(WorkflowTemplate $template, array $stages, ?string $revisionHubCode, bool $hubNaTerminates = true, bool $reviseWithOwner = false): void
    {
        $created = [];
        foreach ($stages as $s) {
            $stage = $this->createStage($template, $s, $revisionHubCode);

            foreach ($s['user_ids'] as $userId) {
                $stage->approvers()->create(['user_id' => $userId]);
            }

            $created[$s['code']] = $stage;
        }

        $reviseWithOwner
            ? $this->wireTransitionsReviseWithOwner($created)
            : $this->wireTransitions($created, $revisionHubCode, $hubNaTerminates);
    }

    private function createStage(WorkflowTemplate $template, array $s, ?string $revisionHubCode): WorkflowStage
    {
        return WorkflowStage::create([
            'workflow_template_id' => $template->id,
            'sequence_no' => $s['seq'],
            'name' => $s['name'],
            'code' => $s['code'],
            'parallel_group' => $s['parallel_group'] ?? null,
            'approval_mode' => $s['mode'] ?? 'any_one',
            'is_revision_stage' => $revisionHubCode !== null && $s['code'] === $revisionHubCode,
            'is_final_distribution_stage' => $s['final'] ?? false,
        ]);
    }

    /**
     * @param  array<string, WorkflowStage>  $created  keyed by stage code
     */
    private function wireTransitions(array $created, string $revisionHubCode, bool $hubNaTerminates): void
    {
        $hub = $created[$revisionHubCode];

        foreach ($created as $code => $stage) {
            WorkflowTransition::create([
                'workflow_stage_id' => $stage->id,
                'decision' => 'approved',
                'outcome_type' => $stage->is_final_distribution_stage ? 'complete_approved' : 'next_stage',
            ]);

            if ($code === $revisionHubCode) {
                // At the revision hub itself: AwC means "still needs work, stay here for another pass".
                WorkflowTransition::create([
                    'workflow_stage_id' => $stage->id,
                    'decision' => 'approved_with_changes',
                    'outcome_type' => 'return_to_stage',
                    'target_stage_id' => $stage->id,
                    'resume_at_stage_id' => $stage->id,
                ]);

                // NA at the very first gate normally terminates the document outright.
                // Some templates (e.g. Workflow 3's "New Versions after NA") instead loop
                // it back for a fresh version rather than hard-rejecting.
                WorkflowTransition::create([
                    'workflow_stage_id' => $stage->id,
                    'decision' => 'not_approved',
                    'outcome_type' => $hubNaTerminates ? 'terminate_rejected' : 'return_to_stage',
                    'target_stage_id' => $hubNaTerminates ? null : $stage->id,
                    'resume_at_stage_id' => $hubNaTerminates ? null : $stage->id,
                ]);
            } else {
                // Any later stage's AwC/NA sends it back to the revision hub; once the hub
                // clears the revised version, the engine resumes at the stage that sent it back.
                foreach (['approved_with_changes', 'not_approved'] as $decision) {
                    WorkflowTransition::create([
                        'workflow_stage_id' => $stage->id,
                        'decision' => $decision,
                        'outcome_type' => 'return_to_stage',
                        'target_stage_id' => $hub->id,
                        'resume_at_stage_id' => $stage->id,
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, WorkflowStage>  $created  keyed by stage code, in sequence_no order
     */
    private function wireTransitionsReviseWithOwner(array $created): void
    {
        $stagesInOrder = array_values($created);
        $firstStageId = $stagesInOrder[0]->id;

        // Only the first (lowest sequence_no) stage in each parallel_group gets
        // transition rows - WorkflowEngine always resolves a group via its
        // representative stage (see WorkflowStage::groupSiblings()).
        $primaryOfGroup = [];
        foreach ($stagesInOrder as $stage) {
            if ($stage->parallel_group && ! isset($primaryOfGroup[$stage->parallel_group])) {
                $primaryOfGroup[$stage->parallel_group] = $stage->id;
            }
        }

        foreach ($stagesInOrder as $stage) {
            $isPrimary = ! $stage->parallel_group || $primaryOfGroup[$stage->parallel_group] === $stage->id;

            if (! $isPrimary) {
                continue;
            }

            WorkflowTransition::create([
                'workflow_stage_id' => $stage->id,
                'decision' => 'approved',
                'outcome_type' => $stage->is_final_distribution_stage ? 'complete_approved' : 'next_stage',
            ]);

            // AwC always goes back to the document owner to revise and upload a new
            // version - review resumes right here (or, for a parallel_group, across
            // the whole group again) once that new version is submitted.
            WorkflowTransition::create([
                'workflow_stage_id' => $stage->id,
                'decision' => 'approved_with_changes',
                'outcome_type' => 'return_to_owner',
                'resume_at_stage_id' => $stage->id,
            ]);

            // Not Approved at the very first gate is a hard stop (nothing to revise
            // back to yet); anywhere else it's the same owner-revision loop as AwC.
            WorkflowTransition::create([
                'workflow_stage_id' => $stage->id,
                'decision' => 'not_approved',
                'outcome_type' => $stage->id === $firstStageId ? 'terminate_rejected' : 'return_to_owner',
                'resume_at_stage_id' => $stage->id === $firstStageId ? null : $stage->id,
            ]);
        }
    }
}
