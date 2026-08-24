<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\Concerns\WiresLinearApprovalChain;
use Illuminate\Database\Seeder;

/**
 * "PromoMats Workflow" for PDF / JPG / GIF format material, wired to the specific
 * named Himalaya Wellness approvers from the brief (see HimalayaWellnessUsersSeeder)
 * rather than generic roles - a stage's "either of these people" (e.g. TM/AGM) is
 * modelled as multiple user-id approvers with approval_mode=any_one:
 *
 *   Brand Brief (Dhanu) -> Agency (Aditi) -> Internal Checks (Aditi) -> Content
 *   Alignment (Dhanu) -> Uploaded on PromoMats -> Content Manager Review (Rathna)
 *   -> TM/AGM Approval (Prathamesh / Dr Hemanth) -> MLR Review: Medical (Ann) ||
 *   Regulatory (Dhanu) || Legal (Kounnteya), all three running in PARALLEL -> Final
 *   Approval / Chairperson (Devansh) -> Approved for Distribution.
 *
 * Only the steps that are an actual sign-off gate become a WorkflowStage; brand
 * brief / drafting / upload steps happen before the document enters this system's
 * approval engine. MLR's three tracks share parallel_group='MLR' - the engine opens
 * all three at once and waits for every one of them to resolve before moving on to
 * Chairperson (see WorkflowStage::groupSiblings() / WorkflowEngine's fan-out/fan-in).
 * Approved with Changes or Not Approved at any gate (other than a hard NA at the very
 * first gate) parks the document for the Owner to revise and re-upload - it does not
 * detour through a separate "revision hub" review (reviseWithOwner: true on
 * WiresLinearApprovalChain).
 */
class HimalayaPromoMatsPdfWorkflowSeeder extends Seeder
{
    use WiresLinearApprovalChain;

    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->first() ?? User::first();
        $userId = fn (string $email) => User::where('email', $email)->value('id');

        $rathna = $userId('rathna.b@himalayawellness.com');
        $prathamesh = $userId('prathamesh.pandurkar@himalayawellness.com');
        $drHemanth = $userId('dr.hemanth@himalayawellness.com');
        $ann = $userId('ann.francis@himalayawellness.com');
        $dhanu = $userId('dhanu@himalayawellness.com');
        $kounnteya = $userId('kounnteya.maurya@himalayawellness.com');
        $devansh = $userId('devansh.parikh@himalayawellness.com');

        if (! $rathna || ! $prathamesh || ! $drHemanth || ! $ann || ! $dhanu || ! $kounnteya || ! $devansh) {
            $this->command?->warn('HimalayaPromoMatsPdfWorkflowSeeder skipped: run HimalayaWellnessUsersSeeder first.');

            return;
        }

        $template = WorkflowTemplate::firstOrCreate(
            ['code' => 'HW_PROMOMATS_PDF'],
            [
                'name' => 'PromoMats Workflow (PDF / JPG / GIF)',
                'description' => 'Brand Brief -> Agency content/artwork development -> Internal checks -> Content alignment -> Uploaded on PromoMats -> Content Manager Review -> TM/AGM Approval -> MLR Review (Medical/Regulatory/Legal) -> Final Approval (Chairperson) -> Approved for Distribution.',
                'applies_to_category' => 'PDF / JPG / GIF',
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
            ['seq' => 1, 'name' => 'Content Manager Review', 'code' => 'CONTENT_MGR', 'user_ids' => [$rathna]],
            ['seq' => 2, 'name' => 'TM/AGM Approval', 'code' => 'TM_AGM', 'user_ids' => [$prathamesh, $drHemanth]],
            ['seq' => 3, 'name' => 'MLR Review - Medical', 'code' => 'MLR_MEDICAL', 'user_ids' => [$ann], 'parallel_group' => 'MLR'],
            ['seq' => 4, 'name' => 'MLR Review - Regulatory', 'code' => 'MLR_REGULATORY', 'user_ids' => [$dhanu], 'parallel_group' => 'MLR'],
            ['seq' => 5, 'name' => 'MLR Review - Legal', 'code' => 'MLR_LEGAL', 'user_ids' => [$kounnteya], 'parallel_group' => 'MLR'],
            ['seq' => 6, 'name' => 'Final Approval - Chairperson', 'code' => 'CHAIRPERSON', 'user_ids' => [$devansh], 'final' => true],
        ], revisionHubCode: null, reviseWithOwner: true);
    }
}
