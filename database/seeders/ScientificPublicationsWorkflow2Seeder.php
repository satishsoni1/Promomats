<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\Concerns\WiresLinearApprovalChain;
use Illuminate\Database\Seeder;

/**
 * Scientific Publications - Workflow 2 (the short path), for material that only
 * needs a single line-manager sign-off before production:
 *
 *   Document Owner -> Line Manager -> Approved for Production -> Approved for
 *   Distribution.
 *
 * The one gate is the Line Manager approval; "Document Owner" is the submitter,
 * not a stage, and the two "Approved for ..." boxes are the outcome of the gate.
 * The brief names no individual line manager for this team, so it's wired to the
 * AGM - Scientific Publications (Dr Jayashree Keshav), who is the team's line
 * manager; owner_can_customize_workflow is on so a document can instead be sent
 * to whoever the real reporting manager is for that piece of work.
 */
class ScientificPublicationsWorkflow2Seeder extends Seeder
{
    use WiresLinearApprovalChain;

    public function run(): void
    {
        $admin = User::where('email', 'admin@globalspace.in')->first() ?? User::first();
        $lineManager = User::where('email', 'dr.jayashree@himalayawellness.com')->value('id');

        if (! $lineManager) {
            $this->command?->warn('ScientificPublicationsWorkflow2Seeder skipped: run ScientificPublicationsUsersSeeder first.');

            return;
        }

        $template = WorkflowTemplate::firstOrCreate(
            ['code' => 'SCIPUB_WF2'],
            [
                'name' => 'Scientific Publications - Workflow 2 (Line Manager)',
                'description' => 'Document Owner -> Line Manager approval -> Approved for Production -> Approved for Distribution.',
                'applies_to_category' => 'Scientific Publication (Proof)',
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
            ['seq' => 1, 'name' => 'Line Manager Approval', 'code' => 'SP2_LINE_MANAGER', 'user_ids' => [$lineManager], 'final' => true],
        ], revisionHubCode: null, reviseWithOwner: true);
    }
}
