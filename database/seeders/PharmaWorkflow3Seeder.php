<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\Concerns\WiresLinearApprovalChain;
use Illuminate\Database\Seeder;

/**
 * "Pharma Workflow 3" (Adaptations of Approved Content) - the lightweight path for
 * re-versioning content that has already cleared full review elsewhere: a single
 * TM/AGM Marketing sign-off. AwC/NA sends it back to the Content Creator for a new
 * version ("New Versions after NA" in the diagram) and resubmission to the same gate.
 */
class PharmaWorkflow3Seeder extends Seeder
{
    use WiresLinearApprovalChain;

    public function run(): void
    {
        $admin = User::where('email', 'admin@globalspace.in')->first() ?? User::first();

        $template = WorkflowTemplate::firstOrCreate(
            ['code' => 'WF3'],
            [
                'name' => 'Pharma Workflow 3 (Adaptations of Approved Content)',
                'description' => 'Adaptation of already-approved content: single TM/AGM Marketing sign-off. AwC/NA sends it back to the Content Creator for a new version and resubmission.',
                'applies_to_category' => 'Adaptation of Approved Content',
                // Lightweight single sign-off - the right depth for content that isn't
                // freshly reaching a patient/HCP/payer, e.g. internal or rep-facing reuse.
                'target_audiences' => ['internal', 'sales_rep'],
                'is_active' => true,
                'created_by' => $admin->id,
            ]
        );

        // Keep tags in sync even if this seeder runs again against an already-seeded row.
        $template->update(['target_audiences' => ['internal', 'sales_rep']]);

        if ($template->stages()->exists()) {
            return;
        }

        $this->wireStages($template, [
            ['seq' => 1, 'name' => 'TM/AGM Marketing Approval', 'code' => 'TM_AGM', 'role' => 'TM/AGM Marketing', 'final' => true],
        ], revisionHubCode: 'TM_AGM', hubNaTerminates: false);
    }
}
