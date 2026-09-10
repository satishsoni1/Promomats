<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\Concerns\WiresLinearApprovalChain;
use Illuminate\Database\Seeder;

/**
 * "Pharma Workflow 1" - new promotional material, from the approval-flow diagram:
 *
 *   Content Manager -> TM/AGM Marketing -> Regulatory L1 -> Regulatory L2 -> Legal L1
 *   -> Legal L2 -> R&D -> Chairperson's Office (Content Manager) -> Content Manager
 *   (post-production pass) -> Design (Internal) -> Approved for Distribution
 *
 * AwC/NA from any stage after Content Manager routes back to Content Manager for revision
 * and resumes at the stage that sent it back once the revision clears. AwC at Content
 * Manager itself means "keep revising here"; NA at Content Manager terminates the document.
 * See WiresLinearApprovalChain for the parallel-vs-sequential Regulatory/Legal/R&D note.
 */
class PharmaWorkflowSeeder extends Seeder
{
    use WiresLinearApprovalChain;

    public function run(): void
    {
        $admin = User::where('email', 'admin@globalspace.in')->first() ?? User::first();

        $template = WorkflowTemplate::firstOrCreate(
            ['code' => 'WF1'],
            [
                'name' => 'Pharma Workflow 1',
                'description' => 'New promotional material: Content Manager -> TM/AGM Marketing -> Regulatory (L1/L2) -> Legal (L1/L2) -> R&D -> Chairperson\'s Office -> post-production Content Manager pass -> Design (Internal) -> Approved for Distribution.',
                'applies_to_category' => 'Promotional Material',
                // Full Regulatory/Legal/R&D chain - the right depth for anything that
                // ultimately reaches a patient, HCP, payer, or the general public.
                'target_audiences' => ['patient', 'hcp', 'payer', 'general_public'],
                'is_active' => true,
                'created_by' => $admin->id,
            ]
        );

        // Keep tags in sync even if this seeder runs again against an already-seeded row.
        $template->update(['target_audiences' => ['patient', 'hcp', 'payer', 'general_public']]);

        if ($template->stages()->exists()) {
            return; // already seeded
        }

        $this->wireStages($template, [
            ['seq' => 1, 'name' => 'Content Manager Review', 'code' => 'CONTENT_MGR', 'role' => 'Content Manager'],
            ['seq' => 2, 'name' => 'TM/AGM Marketing Approval', 'code' => 'TM_AGM', 'role' => 'TM/AGM Marketing'],
            ['seq' => 3, 'name' => 'Regulatory Level 1', 'code' => 'REG_L1', 'role' => 'Regulatory Level 1'],
            ['seq' => 4, 'name' => 'Regulatory Level 2', 'code' => 'REG_L2', 'role' => 'Regulatory Level 2'],
            ['seq' => 5, 'name' => 'Legal Level 1', 'code' => 'LEGAL_L1', 'role' => 'Legal Level 1'],
            ['seq' => 6, 'name' => 'Legal Level 2', 'code' => 'LEGAL_L2', 'role' => 'Legal Level 2'],
            ['seq' => 7, 'name' => 'R&D Sign-off', 'code' => 'RND', 'role' => 'R&D'],
            ['seq' => 8, 'name' => "Chairperson's Office - Approved for Production", 'code' => 'CHAIRPERSON', 'role' => "Chairperson's Office"],
            ['seq' => 9, 'name' => 'Content Manager - Post-Production Review', 'code' => 'CONTENT_MGR_2', 'role' => 'Content Manager'],
            ['seq' => 10, 'name' => 'Design (Internal) - Approved for Distribution', 'code' => 'DESIGN_FINAL', 'role' => 'Design (Internal)', 'final' => true],
        ], revisionHubCode: 'CONTENT_MGR');
    }
}
