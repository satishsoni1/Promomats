<?php

namespace Database\Seeders\Concerns;

use App\Models\Role;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;

/**
 * Shared helper for building a template's stages + A/AwC/NA transition rules from a
 * flat stage list, used by all the Pharma/Himalaya workflow seeders. Transitions
 * follow App\Support\Workflow\TransitionWiring (Approved / Approved with changes
 * move on; Not approved goes back to the Brand Manager and returns to the same
 * stage). The revision models below are historical - they are no longer wired.
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
        $roleId = fn (string $name) => Role::where('slug', \Illuminate\Support\Str::slug($name))->value('id');

        $created = [];
        foreach ($stages as $s) {
            $stage = $this->createStage($template, $s, $revisionHubCode);

            if ($rid = $roleId($s['role'])) {
                $stage->approvers()->create(['role_id' => $rid]);
            }

            $created[$s['code']] = $stage;
        }

        $this->wireStandardTransitions($created);
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

        $this->wireStandardTransitions($created);
    }

    /**
     * Every seeded flow now uses the standard routing (Himalaya UAT round 2): the
     * legacy "revision hub" and terminate-on-first-stage rules are no longer wired.
     * $revisionHubCode / $hubNaTerminates / $reviseWithOwner are kept for signature
     * compatibility (the hub stage is still flagged is_revision_stage).
     *
     * @param  array<string, WorkflowStage>  $created
     */
    private function wireStandardTransitions(array $created): void
    {
        \App\Support\Workflow\TransitionWiring::wire(array_values($created));
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
}
