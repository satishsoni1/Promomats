<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\Concerns\WiresLinearApprovalChain;
use Illuminate\Database\Seeder;

/**
 * Scientific Publications - Workflow 1 (the full proof cycle), for MS Word source
 * material. Built from "Scientific Publications Workflows_ForVODOPromoMats" +
 * the team's user list. Modelled at sign-off-gate granularity, the same way the
 * HimalayaPromoMats seeders are: the Graphic Designer proof-generation / binder /
 * DP / Pre-MP production steps happen between gates and aren't approval stages, so
 * only the review/approval points become WorkflowStages:
 *
 *   Content Review (Resource Selection) -> Copy Editing -> Proof Zero (Project
 *   Lead) -> Proof One (Proofreading) -> Proof Two [Project Lead || AGM] -> Proof
 *   Three [Project Lead || Proofreading] -> Proof Four review [Regulatory || Legal
 *   || Document Owner], all in PARALLEL -> Proof Four Feedback -> Proof Five ->
 *   Check Pre-MP & approve for MP -> Check MP & approve for Printing / Approved
 *   for Distribution.
 *
 * Stages wired to a pool ("any Project Lead") use approval_mode any_one. This
 * template has owner_can_customize_workflow = true, so at upload the document
 * owner can send a pooled stage to one named person (e.g. the specific Project
 * Lead for that publication) - see DocumentController::resolveStageApproverPicks().
 * AwC/NA parks the document for the owner to revise and re-upload
 * (reviseWithOwner: true); NA at the very first gate is a hard stop.
 */
class ScientificPublicationsWorkflow1Seeder extends Seeder
{
    use WiresLinearApprovalChain;

    public function run(): void
    {
        $admin = User::where('email', 'admin@globalspace.in')->first() ?? User::first();
        $userId = fn (string $email) => User::where('email', $email)->value('id');

        $ids = [
            'anna' => $userId('dr.anna.c@himalayawellness.com'),
            'priyanka' => $userId('dr.priyanka.r@himalayawellness.com'),
            'shruthiK' => $userId('shruthi.kumar@himalayawell.com'),
            'chaitra' => $userId('dr.chaitra.g@himalayawellness.com'),
            'harika' => $userId('harika.gs@himalayawellness.com'),
            'shruthiM' => $userId('shruthi.murali@himalayawellness.com'),
            'jayashree' => $userId('dr.jayashree@himalayawellness.com'),
            'vijendra' => $userId('dr.vijendra@himalayawellness.com'),
            'julie' => $userId('julie.buragohain@himalayawellness.com'),
            'swaroop' => $userId('swaroop.mahesh@himalayawellness.com'),
        ];

        if (in_array(null, $ids, true)) {
            $this->command?->warn('ScientificPublicationsWorkflow1Seeder skipped: run ScientificPublicationsUsersSeeder first.');

            return;
        }

        $projectLeads = [$ids['anna'], $ids['priyanka'], $ids['shruthiK'], $ids['chaitra'], $ids['harika'], $ids['shruthiM']];
        $proofreaders = [$ids['harika'], $ids['shruthiM']];
        $contentReviewers = [$ids['shruthiK'], $ids['chaitra']];
        $copyEditors = [$ids['harika'], $ids['shruthiM']];
        $legal = [$ids['julie'], $ids['swaroop']];

        $template = WorkflowTemplate::firstOrCreate(
            ['code' => 'SCIPUB_WF1'],
            [
                'name' => 'Scientific Publications - Workflow 1 (Full Proof Cycle)',
                'description' => 'Content Review -> Copy Editing -> Proof Zero -> Proof One -> Proof Two (Project Lead || AGM) -> Proof Three (Project Lead || Proofreading) -> Proof Four review (Regulatory || Legal || Document Owner) -> Proof Four Feedback -> Proof Five -> Check Pre-MP -> Check MP / Approved for Distribution.',
                'applies_to_category' => 'Scientific Publication (Word)',
                'target_audiences' => ['hcp'],
                'is_active' => true,
                'owner_can_customize_workflow' => true,
                'created_by' => $admin->id,
            ]
        );

        $template->update([
            'target_audiences' => ['hcp'],
            'owner_can_customize_workflow' => true,
        ]);

        if ($template->stages()->exists()) {
            return; // already seeded
        }

        $this->wireStagesWithUsers($template, [
            ['seq' => 1, 'name' => 'Content Review (Resource Selection)', 'code' => 'SP_CONTENT_REVIEW', 'user_ids' => $contentReviewers],
            ['seq' => 2, 'name' => 'Copy Editing', 'code' => 'SP_COPY_EDIT', 'user_ids' => $copyEditors],
            ['seq' => 3, 'name' => 'Proof Zero Review', 'code' => 'SP_PROOF_ZERO', 'user_ids' => $projectLeads],
            ['seq' => 4, 'name' => 'Proof One - Proofreading', 'code' => 'SP_PROOF_ONE', 'user_ids' => $proofreaders],
            ['seq' => 5, 'name' => 'Proof Two - Project Lead', 'code' => 'SP_PROOF_TWO_PL', 'user_ids' => $projectLeads, 'parallel_group' => 'SP_PROOF_TWO'],
            ['seq' => 6, 'name' => 'Proof Two - AGM', 'code' => 'SP_PROOF_TWO_AGM', 'user_ids' => [$ids['jayashree']], 'parallel_group' => 'SP_PROOF_TWO'],
            ['seq' => 7, 'name' => 'Proof Three - Project Lead', 'code' => 'SP_PROOF_THREE_PL', 'user_ids' => $projectLeads, 'parallel_group' => 'SP_PROOF_THREE'],
            ['seq' => 8, 'name' => 'Proof Three - Proofreading', 'code' => 'SP_PROOF_THREE_PROOF', 'user_ids' => $proofreaders, 'parallel_group' => 'SP_PROOF_THREE'],
            ['seq' => 9, 'name' => 'Proof Four - Regulatory Affairs', 'code' => 'SP_REGULATORY', 'user_ids' => [$ids['vijendra']], 'parallel_group' => 'SP_PROOF_FOUR'],
            ['seq' => 10, 'name' => 'Proof Four - Legal', 'code' => 'SP_LEGAL', 'user_ids' => $legal, 'parallel_group' => 'SP_PROOF_FOUR'],
            ['seq' => 11, 'name' => 'Proof Four - Document Owner (Chairperson\'s Office)', 'code' => 'SP_DOC_OWNER', 'user_ids' => $projectLeads, 'parallel_group' => 'SP_PROOF_FOUR'],
            ['seq' => 12, 'name' => 'Proof Four Feedback', 'code' => 'SP_PROOF_FOUR_FEEDBACK', 'user_ids' => $projectLeads],
            ['seq' => 13, 'name' => 'Proof Five Review', 'code' => 'SP_PROOF_FIVE', 'user_ids' => $projectLeads],
            ['seq' => 14, 'name' => 'Check Pre-MP & Approve for MP Generation', 'code' => 'SP_PRE_MP', 'user_ids' => $projectLeads],
            ['seq' => 15, 'name' => 'Check MP & Approve for Printing', 'code' => 'SP_MP_PRINT', 'user_ids' => $projectLeads, 'final' => true],
        ], revisionHubCode: null, reviseWithOwner: true);
    }
}
