<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use App\Support\Workflow\TransitionWiring;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data side of Himalaya UAT round 2 ("fine tuning required"). Safe to re-run.
 *
 * New versions of the four live promotional workflows. Jobs already in flight stay
 * on the version they started with.
 *
 *  Himalaya PromoMats (PDF/JPG/GIF and Word/Video/PPT):
 *    Draft Alignment - Content Manager (to and fro with the Brand Manager; once
 *    aligned it comes back to the Brand Manager, who commissions the creative from
 *    the Content Agency / Design Team and triggers the review)
 *    -> Content Manager Review -> TM/AGM Approval
 *    -> MLR: R&D || Regulatory || Legal (parallel; the Brand Manager picks who)
 *    -> Chairperson Approval (offline - the Content Manager uploads the signed copy)
 *    => Approved for Production
 *
 *  Pharma Workflow 1 / 2 (still in use - this is where "R&D Sign-off" and the
 *  sequential MLR came from):
 *    Content Manager Review -> TM/AGM Marketing Approval
 *    -> MLR: Regulatory L1 || Regulatory L2 || Legal L1 || Legal L2 || R&D (parallel)
 *    -> Chairperson Approval (offline) => Approved for Production
 *    The Document Owner review / post-production passes are dropped: the Brand
 *    Manager owns the job and makers can't be checkers.
 *
 * Routing everywhere: App\Support\Workflow\TransitionWiring.
 */
class HimalayaUatRoundTwoSeeder extends Seeder
{
    public const CHAIRPERSON = ['CHAIRPERSON', 'Chairperson Approval (offline)', ['content-manager'], 'offline', null];

    public function run(): void
    {
        $admin = User::where('email', 'admin@globalspace.in')->first() ?? User::first();
        if (! $admin) {
            return;
        }

        Role::firstOrCreate(['slug' => 'brand-manager'], ['name' => 'Brand Manager', 'is_system' => false]);
        Role::firstOrCreate(['slug' => 'content-agency'], ['name' => 'Content Agency', 'is_system' => false]);

        $himalaya = [
            ['DRAFT_CM', 'Draft Alignment - Content Manager', ['content-manager'], 'draft', null],
            ['CONTENT_MGR', 'Content Manager Review', ['content-manager'], 'review', null],
            ['TM_AGM', 'TM/AGM Approval', ['tmagm', 'tmagm-marketing'], 'review', null],
            ['MLR_RND', 'R&D', ['rd', 'medical'], 'review', 'MLR'],
            ['MLR_REGULATORY', 'Regulatory', ['regulatory'], 'review', 'MLR'],
            ['MLR_LEGAL', 'Legal', ['legal'], 'review', 'MLR'],
            self::CHAIRPERSON,
        ];

        $pharma = [
            ['CONTENT_MGR', 'Content Manager Review', ['content-manager'], 'review', null],
            ['TM_AGM', 'TM/AGM Marketing Approval', ['tmagm-marketing', 'tmagm'], 'review', null],
            ['REG_L1', 'Regulatory Level 1', ['regulatory-level-1'], 'review', 'MLR'],
            ['REG_L2', 'Regulatory Level 2', ['regulatory-level-2'], 'review', 'MLR'],
            ['LEGAL_L1', 'Legal Level 1', ['legal-level-1'], 'review', 'MLR'],
            ['LEGAL_L2', 'Legal Level 2', ['legal-level-2'], 'review', 'MLR'],
            ['RND', 'R&D', ['rd', 'medical'], 'review', 'MLR'],
            self::CHAIRPERSON,
        ];

        $descriptions = [
            'himalaya' => 'Draft Alignment (Content Manager <-> Brand Manager) -> Brand Manager commissions the creative (Content Agency / Design Team) -> Content Manager Review -> TM/AGM Approval -> MLR (R&D || Regulatory || Legal, in parallel) -> Chairperson Approval (offline, uploaded by the Content Manager) -> Approved for Production.',
            'pharma' => 'Content Manager Review -> TM/AGM Marketing Approval -> MLR (Regulatory L1/L2 || Legal L1/L2 || R&D, in parallel) -> Chairperson Approval (offline, uploaded by the Content Manager) -> Approved for Production.',
        ];

        foreach (['HW_PROMOMATS_PDF' => 'himalaya', 'HW_PROMOMATS_DOC' => 'himalaya', 'WF1' => 'pharma', 'WF2' => 'pharma'] as $family => $kind) {
            $this->revise($family, $kind === 'himalaya' ? $himalaya : $pharma, $descriptions[$kind], $admin);
        }
    }

    /**
     * @param  array<int, array{0:string,1:string,2:string[],3:string,4:?string}>  $plan
     */
    protected function revise(string $family, array $plan, string $description, User $admin): void
    {
        $current = WorkflowTemplate::where('family_code', $family)->where('is_private', false)
            ->orderByDesc('version')->first();

        if (! $current || $current->stages()->where('code', 'CHAIRPERSON')->where('stage_type', 'offline')->exists()) {
            return; // not seeded here, or already revised
        }

        DB::transaction(function () use ($current, $plan, $description, $admin) {
            $new = $current->createNewVersion($admin);
            $new->stages()->get()->each->delete();
            $new->update([
                'owner_can_customize_workflow' => true,
                'awc_resume' => 'next_stage',
                'completion_status' => 'approved_for_production',
                'description' => $description,
            ]);

            $stages = [];
            foreach ($plan as $i => [$code, $name, $roleSlugs, $type, $group]) {
                $stage = WorkflowStage::create([
                    'workflow_template_id' => $new->id,
                    'sequence_no' => $i + 1,
                    'name' => $name,
                    'code' => $code,
                    'stage_type' => $type,
                    'parallel_group' => $group,
                    'approval_mode' => 'any_one',
                    'is_final_distribution_stage' => $i === count($plan) - 1,
                    'sla_hours' => 48,
                ]);
                foreach (Role::whereIn('slug', $roleSlugs)->pluck('id') as $roleId) {
                    $stage->approvers()->create(['role_id' => $roleId]);
                }
                $stages[] = $stage;
            }

            TransitionWiring::wire($stages);

            WorkflowRule::where('workflow_template_id', $current->id)->update(['workflow_template_id' => $new->id]);
        });
    }
}
