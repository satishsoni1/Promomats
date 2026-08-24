<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\Concerns\WiresLinearApprovalChain;
use Illuminate\Database\Seeder;

/**
 * "PromoMats Workflow" for Word / Video / PPT format material, wired to the specific
 * named Himalaya Wellness approvers from the brief (see HimalayaWellnessUsersSeeder):
 *
 *   Document Draft (Monika) -> Content Manager Brief Review (Jalba) -> Content
 *   Creator artwork development (Deepak) -> Artwork Review (Monika) -> Content
 *   Manager Review (Jalba) -> TM/AGM Approval (Bhupender / Dr Hemanth) -> MLR
 *   Review: Medical (Akansha) -> Regulatory (Smriti) -> Legal (Aditi) -> Final
 *   Approval / Chairperson (Rengarajan) -> Approved for Distribution.
 *
 * Only the steps that are an actual sign-off gate become a WorkflowStage; drafting
 * and artwork-development steps happen before/between gates rather than as gates
 * themselves. MLR's three tracks (Medical/Regulatory/Legal) share
 * parallel_group='MLR' and run concurrently - the engine waits for all three to
 * resolve before moving on to the Chairperson (see WorkflowStage::groupSiblings()).
 * Approved with Changes or Not Approved at any gate (other than a hard NA at the
 * very first gate) parks the document for the Owner to revise and re-upload, rather
 * than detouring through a separate revision-hub review (reviseWithOwner: true on
 * WiresLinearApprovalChain).
 */
class HimalayaPromoMatsDocWorkflowSeeder extends Seeder
{
    use WiresLinearApprovalChain;

    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->first() ?? User::first();
        $userId = fn (string $email) => User::where('email', $email)->value('id');

        $jalba = $userId('jalba.rc@himalayawellness.com');
        $monika = $userId('monika.pant@himalayawellness.com');
        $bhupender = $userId('bhupender.singh@himalayawellness.com');
        $drHemanth = $userId('dr.hemanth@himalayawellness.com');
        $akansha = $userId('akansha.thakur@himalayawellness.com');
        $smriti = $userId('smriti.singh@himalayawellness.com');
        $aditi = $userId('aditi.dasgupta@himalayawellness.com');
        $rengarajan = $userId('rengarajan.p@himalayawellness.com');

        if (! $jalba || ! $monika || ! $bhupender || ! $drHemanth || ! $akansha || ! $smriti || ! $aditi || ! $rengarajan) {
            $this->command?->warn('HimalayaPromoMatsDocWorkflowSeeder skipped: run HimalayaWellnessUsersSeeder first.');

            return;
        }

        $template = WorkflowTemplate::firstOrCreate(
            ['code' => 'HW_PROMOMATS_DOC'],
            [
                'name' => 'PromoMats Workflow (Word / Video / PPT)',
                'description' => 'Document Draft -> Content Manager Brief Review -> Content Creator artwork development -> Artwork Review -> Content Manager Review -> TM/AGM Approval -> MLR Review (Medical/Regulatory/Legal) -> Final Approval (Chairperson) -> Approved for Distribution.',
                'applies_to_category' => 'Word / Video / PPT',
                'target_audiences' => ['patient', 'hcp', 'payer', 'general_public'],
                'is_active' => true,
                'created_by' => $admin->id,
            ]
        );

        $template->update(['target_audiences' => ['patient', 'hcp', 'payer', 'general_public']]);

        if ($template->stages()->exists()) {
            return; // already seeded
        }

        $this->wireStagesWithUsers($template, [
            ['seq' => 1, 'name' => 'Content Manager Brief Review', 'code' => 'CONTENT_MGR_BRIEF', 'user_ids' => [$jalba]],
            ['seq' => 2, 'name' => 'Artwork Review', 'code' => 'ARTWORK_REVIEW', 'user_ids' => [$monika]],
            ['seq' => 3, 'name' => 'Content Manager Review', 'code' => 'CONTENT_MGR', 'user_ids' => [$jalba]],
            ['seq' => 4, 'name' => 'TM/AGM Approval', 'code' => 'TM_AGM', 'user_ids' => [$bhupender, $drHemanth]],
            ['seq' => 5, 'name' => 'MLR Review - Medical', 'code' => 'MLR_MEDICAL', 'user_ids' => [$akansha], 'parallel_group' => 'MLR'],
            ['seq' => 6, 'name' => 'MLR Review - Regulatory', 'code' => 'MLR_REGULATORY', 'user_ids' => [$smriti], 'parallel_group' => 'MLR'],
            ['seq' => 7, 'name' => 'MLR Review - Legal', 'code' => 'MLR_LEGAL', 'user_ids' => [$aditi], 'parallel_group' => 'MLR'],
            ['seq' => 8, 'name' => 'Final Approval - Chairperson', 'code' => 'CHAIRPERSON', 'user_ids' => [$rengarajan], 'final' => true],
        ], revisionHubCode: null, reviseWithOwner: true);
    }
}
