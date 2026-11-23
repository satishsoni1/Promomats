<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Himalaya UAT round 2 ("fine tuning required" list).
 *
 *  - documents.status 'draft_aligned': the Content Manager has signed off the draft;
 *    the job is back with the Brand Manager to commission the creative (Content
 *    Agency / internal Design Team) and then trigger the review themselves.
 *  - document_comments.published_at: a reviewer's comments stay a private draft
 *    until they submit their review (null = draft). Existing comments are published.
 *  - workflow_templates.completion_status: what a finished workflow marks the
 *    document as. The Chairperson flows end at "Approved for Production".
 *  - Routing on every existing template: "Approved with changes" moves on to the
 *    next stage (it goes back to the Brand Manager only from a draft stage or the
 *    final stage), and "Not approved" always goes back to the Brand Manager and
 *    returns to the same stage once revised - never a hard stop.
 *  - Brand Manager and Document Owner are one role: Document Owner users become
 *    Brand Managers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE documents MODIFY status ENUM(
            'draft', 'in_review', 'approved_with_changes_pending', 'draft_aligned', 'rejected',
            'approved', 'approved_for_production', 'approved_for_distribution',
            'pending_expiration', 'expired', 'superseded', 'obsolete', 'archived'
        ) DEFAULT 'draft'");

        Schema::table('document_comments', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('edited_at');
        });
        DB::table('document_comments')->update(['published_at' => DB::raw('created_at')]);

        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->string('completion_status', 40)->default('approved_for_distribution')->after('awc_resume');
        });

        $this->rerouteDecisions();
        $this->mergeDocumentOwnerIntoBrandManager();
    }

    protected function rerouteDecisions(): void
    {
        $stages = DB::table('workflow_stages')->orderBy('workflow_template_id')->orderBy('sequence_no')->get()->groupBy('workflow_template_id');

        foreach ($stages as $templateStages) {
            $maxSeq = $templateStages->max('sequence_no');
            $lastGroup = $templateStages->firstWhere('sequence_no', $maxSeq)?->parallel_group;

            foreach ($templateStages as $stage) {
                $isFinal = $stage->is_final_distribution_stage
                    || $stage->sequence_no === $maxSeq
                    || ($stage->parallel_group && $stage->parallel_group === $lastGroup);
                $isDraft = ($stage->stage_type ?? 'review') === 'draft';

                $backToOwner = ['outcome_type' => 'return_to_owner', 'target_stage_id' => null, 'resume_at_stage_id' => $stage->id];

                DB::table('workflow_transitions')
                    ->where('workflow_stage_id', $stage->id)
                    ->where('decision', 'not_approved')
                    ->update($backToOwner);

                DB::table('workflow_transitions')
                    ->where('workflow_stage_id', $stage->id)
                    ->where('decision', 'approved_with_changes')
                    ->where('outcome_type', '!=', 'complete_approved')
                    ->update(($isDraft || $isFinal)
                        ? $backToOwner
                        : ['outcome_type' => 'next_stage', 'target_stage_id' => null, 'resume_at_stage_id' => null]);
            }
        }
    }

    protected function mergeDocumentOwnerIntoBrandManager(): void
    {
        $owner = DB::table('roles')->where('slug', 'document-owner')->first();
        if (! $owner) {
            return;
        }

        $bmId = DB::table('roles')->where('slug', 'brand-manager')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Brand Manager', 'slug' => 'brand-manager', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);

        foreach (DB::table('role_user')->where('role_id', $owner->id)->pluck('user_id') as $userId) {
            DB::table('role_user')->updateOrInsert(['role_id' => $bmId, 'user_id' => $userId]);
        }
        DB::table('role_user')->where('role_id', $owner->id)->delete();

        // Brand Managers start jobs and upload, so they carry the old role's upload/view rights.
        $permissionIds = DB::table('permissions')->whereIn('slug', ['upload-documents', 'view-all-documents'])->pluck('id');
        foreach ($permissionIds as $pid) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $bmId, 'permission_id' => $pid]);
        }

        // Keep the role row only while an older workflow version still names it.
        if (! DB::table('workflow_stage_approvers')->where('role_id', $owner->id)->exists()) {
            DB::table('permission_role')->where('role_id', $owner->id)->delete();
            DB::table('roles')->where('id', $owner->id)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('workflow_templates', fn (Blueprint $t) => $t->dropColumn('completion_status'));
        Schema::table('document_comments', fn (Blueprint $t) => $t->dropColumn('published_at'));
        DB::statement("UPDATE documents SET status = 'approved_with_changes_pending' WHERE status = 'draft_aligned'");
        DB::statement("ALTER TABLE documents MODIFY status ENUM(
            'draft', 'in_review', 'approved_with_changes_pending', 'rejected',
            'approved', 'approved_for_production', 'approved_for_distribution',
            'pending_expiration', 'expired', 'superseded', 'obsolete', 'archived'
        ) DEFAULT 'draft'");
    }
};
