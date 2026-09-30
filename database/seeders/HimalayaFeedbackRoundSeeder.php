<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data side of the Himalaya UAT feedback round. Safe to re-run.
 *
 * 1. Design Team role - the whole team acts as Content Creators; work is assigned
 *    to the team and then to an individual designer.
 * 2. Collateral classification - Print / Digital, with the collateral types the
 *    marketing team asked for under each.
 * 3. New versions of both Himalaya PromoMats workflows:
 *      Draft - Content Manager -> Draft - Content Creator (Design Team)
 *      -> Content Manager Review -> TM/AGM Approval
 *      -> MLR Medical || Regulatory || Legal (parallel) -> Chairperson
 *    Every stage is role-based, so the task owner picks the person at upload
 *    (Jalba or Rathna, the right TM/AGM, the brand's MLR reviewers ...) instead
 *    of it being fixed to one name. Documents already in flight stay on the old
 *    version.
 */
class HimalayaFeedbackRoundSeeder extends Seeder
{
    public const PRINT_TYPES = [
        'LBL' => 'LBL (Leave Behind Literature)',
        'LBC' => 'LBC',
        'RETAILER_POSTER' => 'Retailer Poster',
        'VAF' => 'VAF (Visual Aid Folder)',
        'STOCKIST_POSTER' => 'Stockists Poster',
        'IN_CLINIC' => 'In-Clinic Visibility',
        'OUT_CLINIC' => 'Out-Clinic Visibility',
        'DANGLER' => 'Dangler',
        'LEAFLET' => 'Leaflet / Brochure',
        'PACK_INSERT' => 'Pack Insert / Label',
        'STANDEE' => 'Standee / Banner',
        'PRINT_OTHER' => 'Other Print',
    ];

    public const DIGITAL_TYPES = [
        'E_DETAILER' => 'E-Detailer',
        'EMAILER' => 'Emailer / Newsletter',
        'SOCIAL_POST' => 'Social Media Post',
        'WHATSAPP' => 'WhatsApp Creative',
        'VIDEO' => 'Video',
        'WEB_BANNER' => 'Website / Web Banner',
        'WEBINAR' => 'Webinar Creative',
        'DIGITAL_OTHER' => 'Other Digital',
    ];

    public function run(): void
    {
        $admin = User::where('email', 'admin@globalspace.in')->first() ?? User::first();

        $this->seedDesignTeam();
        $this->seedCollateralTypes();

        if ($admin) {
            foreach (['HW_PROMOMATS_PDF', 'HW_PROMOMATS_DOC'] as $family) {
                $this->reviseHimalayaWorkflow($family, $admin);
            }
        }
    }

    protected function seedDesignTeam(): void
    {
        $role = Role::firstOrCreate(['slug' => 'design-team'], ['name' => 'Design Team', 'is_system' => false]);

        // Current Content Creators and internal designers join the Design Team.
        $members = User::whereHas('roles', fn ($q) => $q->whereIn('slug', ['content-creator', 'design-internal', 'content-creator-intext']))->pluck('id');
        $role->users()->syncWithoutDetaching($members);

        foreach (['content-manager', 'tmagm', 'medical', 'regulatory', 'legal', 'chairperson', 'brand-manager'] as $slug) {
            Role::firstOrCreate(['slug' => $slug], ['name' => Str::of($slug)->replace('-', ' ')->title()->replace('Tmagm', 'TM/AGM'), 'is_system' => false]);
        }
    }

    protected function seedCollateralTypes(): void
    {
        foreach (['print' => self::PRINT_TYPES, 'digital' => self::DIGITAL_TYPES] as $channel => $types) {
            foreach ($types as $code => $name) {
                // No extension/size limit: a collateral type says what the piece is,
                // not which file format it arrives in.
                DocumentType::updateOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'channel' => $channel, 'status' => 'active', 'allowed_extensions' => null, 'max_file_size_kb' => null]
                );
            }
        }
    }

    protected function reviseHimalayaWorkflow(string $family, User $admin): void
    {
        $current = WorkflowTemplate::where('family_code', $family)->where('is_active', true)->where('is_private', false)->orderByDesc('version')->first();
        if (! $current || $current->stages()->where('code', 'DRAFT_CM')->exists()) {
            return; // not seeded yet, or already revised
        }

        DB::transaction(function () use ($current, $admin) {
            $new = $current->createNewVersion($admin);
            $new->stages()->get()->each->delete();
            $new->update([
                'owner_can_customize_workflow' => true,
                'awc_resume' => 'next_stage',
                'description' => 'Draft (Content Manager -> Content Creator / Design Team) -> Content Manager Review -> TM/AGM Approval -> MLR Review (Medical || Regulatory || Legal, in parallel) -> Final Approval (Chairperson). The task owner picks the person for each stage.',
            ]);

            $roleId = fn (string $slug) => Role::where('slug', $slug)->value('id');
            $plan = [
                ['DRAFT_CM', 'Draft - Content Manager', 'content-manager', 'draft', null],
                ['DRAFT_CC', 'Draft - Content Creator (Design Team)', 'design-team', 'draft', null],
                ['CONTENT_MGR', 'Content Manager Review', 'content-manager', 'review', null],
                ['TM_AGM', 'TM/AGM Approval', 'tmagm', 'review', null],
                ['MLR_MEDICAL', 'MLR Review - Medical', 'medical', 'review', 'MLR'],
                ['MLR_REGULATORY', 'MLR Review - Regulatory', 'regulatory', 'review', 'MLR'],
                ['MLR_LEGAL', 'MLR Review - Legal', 'legal', 'review', 'MLR'],
                ['CHAIRPERSON', 'Final Approval - Chairperson', 'chairperson', 'review', null],
            ];

            $stages = [];
            foreach ($plan as $i => [$code, $name, $role, $type, $group]) {
                $stage = WorkflowStage::create([
                    'workflow_template_id' => $new->id,
                    'sequence_no' => $i + 1,
                    'name' => $name,
                    'code' => $code,
                    'stage_type' => $type,
                    'parallel_group' => $group,
                    'approval_mode' => 'any_one',
                    'is_final_distribution_stage' => $code === 'CHAIRPERSON',
                    'sla_hours' => 48,
                ]);
                if ($rid = $roleId($role)) {
                    $stage->approvers()->create(['role_id' => $rid]);
                }
                $stages[] = $stage;
            }

            // Approved -> next; Approved with changes / Not Approved -> back to the task
            // owner (who revises or sends it to the Design Team). One primary per
            // parallel group carries the transitions.
            $seenGroups = [];
            foreach ($stages as $stage) {
                if ($stage->parallel_group) {
                    if (isset($seenGroups[$stage->parallel_group])) {
                        continue;
                    }
                    $seenGroups[$stage->parallel_group] = true;
                }
                WorkflowTransition::create([
                    'workflow_stage_id' => $stage->id,
                    'decision' => 'approved',
                    'outcome_type' => $stage->is_final_distribution_stage ? 'complete_approved' : 'next_stage',
                ]);
                foreach (['approved_with_changes', 'not_approved'] as $decision) {
                    WorkflowTransition::create([
                        'workflow_stage_id' => $stage->id,
                        'decision' => $decision,
                        'outcome_type' => 'return_to_owner',
                        'resume_at_stage_id' => $stage->id,
                    ]);
                }
            }

            // Point the auto-assignment rules at the new version.
            WorkflowRule::where('workflow_template_id', $current->id)->update(['workflow_template_id' => $new->id]);
        });
    }
}
