<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\Concerns\WiresLinearApprovalChain;
use Illuminate\Database\Seeder;

/**
 * "Pharma Workflow 2" - same shape as Workflow 1, but content originates from an
 * external Content Agency and the revision hub is the Document Owner rather than the
 * Content Manager:
 *
 *   Document Owner Review -> Content Manager -> TM/AGM Marketing -> Regulatory L1
 *   -> Regulatory L2 -> Legal L1 -> Legal L2 -> R&D -> Chairperson's Office (Content
 *   Manager) -> Document Owner (post-production pass) -> Design (Internal)
 *   -> Approved for Distribution
 */
class PharmaWorkflow2Seeder extends Seeder
{
    use WiresLinearApprovalChain;

    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->first() ?? User::first();

        $template = WorkflowTemplate::firstOrCreate(
            ['code' => 'WF2'],
            [
                'name' => 'Pharma Workflow 2',
                'description' => 'Agency-originated promotional material: Document Owner -> Content Manager -> TM/AGM Marketing -> Regulatory (L1/L2) -> Legal (L1/L2) -> R&D -> Chairperson\'s Office -> post-production Document Owner pass -> Design (Internal) -> Approved for Distribution.',
                'applies_to_category' => 'Promotional Material (Agency)',
                'target_audiences' => ['patient', 'hcp', 'payer', 'general_public'],
                'is_active' => true,
                'created_by' => $admin->id,
            ]
        );

        // Keep tags in sync even if this seeder runs again against an already-seeded row.
        $template->update(['target_audiences' => ['patient', 'hcp', 'payer', 'general_public']]);

        if ($template->stages()->exists()) {
            return;
        }

        $this->wireStages($template, [
            ['seq' => 1, 'name' => 'Document Owner Review', 'code' => 'DOC_OWNER', 'role' => 'Document Owner'],
            ['seq' => 2, 'name' => 'Content Manager Review', 'code' => 'CONTENT_MGR', 'role' => 'Content Manager'],
            ['seq' => 3, 'name' => 'TM/AGM Marketing Approval', 'code' => 'TM_AGM', 'role' => 'TM/AGM Marketing'],
            ['seq' => 4, 'name' => 'Regulatory Level 1', 'code' => 'REG_L1', 'role' => 'Regulatory Level 1'],
            ['seq' => 5, 'name' => 'Regulatory Level 2', 'code' => 'REG_L2', 'role' => 'Regulatory Level 2'],
            ['seq' => 6, 'name' => 'Legal Level 1', 'code' => 'LEGAL_L1', 'role' => 'Legal Level 1'],
            ['seq' => 7, 'name' => 'Legal Level 2', 'code' => 'LEGAL_L2', 'role' => 'Legal Level 2'],
            ['seq' => 8, 'name' => 'R&D Sign-off', 'code' => 'RND', 'role' => 'R&D'],
            ['seq' => 9, 'name' => "Chairperson's Office - Approved for Production", 'code' => 'CHAIRPERSON', 'role' => "Chairperson's Office"],
            ['seq' => 10, 'name' => 'Document Owner - Post-Production Review', 'code' => 'DOC_OWNER_2', 'role' => 'Document Owner'],
            ['seq' => 11, 'name' => 'Design (Internal) - Approved for Distribution', 'code' => 'DESIGN_FINAL', 'role' => 'Design (Internal)', 'final' => true],
        ], revisionHubCode: 'DOC_OWNER');
    }
}
