<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema for the Himalaya UAT feedback round (stakeholder list + Prathamesh's
 * observation sheet): collateral classification, placeholders, per-stage due
 * dates, task reassignment, Design Team work tasks, draft stages, comment
 * editing / replacement text, helpdesk tickets and mobile API tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Collateral classification: Print / Digital, with the collateral types
        // (LBL, LBC, Retailer Poster, VAF, ...) living in document_types under each.
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('channel', 20)->nullable()->after('code'); // print | digital | null (any)
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('channel', 20)->nullable()->after('document_type_id');
            // The job's overall target date - deliberately not tied to expiry_date.
            $table->date('due_date')->nullable()->after('expiry_date');
            // Created without a file: the Design Team uploads the artwork into it.
            $table->boolean('is_placeholder')->default(false)->after('status');
        });

        Schema::table('workflow_stages', function (Blueprint $table) {
            // 'draft' stages (Content Manager / Content Creator drafting) are work
            // steps: the assignee submits the draft on rather than approving it.
            $table->string('stage_type', 20)->default('review')->after('code');
        });

        Schema::table('workflow_templates', function (Blueprint $table) {
            // What happens once a revision is uploaded after "Approved with changes":
            // next_stage = move on (the stage already approved it), same_stage = re-review.
            $table->string('awc_resume', 20)->default('next_stage')->after('owner_can_customize_workflow');
        });

        DB::statement("ALTER TABLE document_stage_assignees MODIFY status ENUM('pending', 'acted', 'skipped', 'superseded', 'reassigned') NOT NULL DEFAULT 'pending'");

        Schema::table('document_stage_assignees', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('assigned_at');
            $table->timestamp('due_soon_notified_at')->nullable()->after('overdue_notified_at');
            $table->foreignId('reassigned_from_id')->nullable()->after('user_id')->constrained('document_stage_assignees')->nullOnDelete();
            $table->foreignId('reassigned_by')->nullable()->after('reassigned_from_id')->constrained('users')->nullOnDelete();
            $table->string('reassign_reason', 500)->nullable()->after('reassigned_by');
        });

        // Task owner's per-document, per-stage due period (default 48h at every stage).
        Schema::create('document_stage_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('due_hours');
            $table->timestamps();
            $table->unique(['document_id', 'workflow_stage_id']);
        });

        // Work handed to a whole team (the Design Team "umbrella") - artwork for a
        // placeholder job, or rework after "Approved with changes". Anyone in the
        // team can pick it up or assign it to an individual designer.
        Schema::create('document_work_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // artwork | rework
            $table->string('team', 50)->default('design');
            $table->string('status', 20)->default('open'); // open | completed | cancelled
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->text('instructions')->nullable();
            $table->boolean('resubmit_on_upload')->default(true);
            $table->timestamp('due_at')->nullable();
            $table->foreignId('completed_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['team', 'status']);
        });

        Schema::table('document_comments', function (Blueprint $table) {
            $table->text('replacement_text')->nullable()->after('selected_text');
            $table->timestamp('edited_at')->nullable()->after('resolved_by');
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('user_id')->constrained();
            $table->string('category', 30); // technical_issue | modification | access | other
            $table->string('priority', 10)->default('normal'); // low | normal | high | urgent
            $table->string('subject');
            $table->text('description');
            $table->string('page_url', 500)->nullable();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('open'); // open | in_progress | resolved | closed
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // Bearer tokens for the mobile app's API (hashed, like passwords).
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // Every template now lets the task owner pick stakeholders per stage.
        DB::table('workflow_templates')->where('is_private', false)->update(['owner_can_customize_workflow' => true]);

        // "Approved with changes" goes back to the task owner everywhere (it used to
        // loop legacy templates back through the Content Manager and re-run every
        // stage). Not Approved keeps each template's configured route.
        DB::table('workflow_transitions')
            ->where('decision', 'approved_with_changes')
            ->where('outcome_type', 'return_to_stage')
            ->update(['outcome_type' => 'return_to_owner', 'target_stage_id' => null]);

        // Documents left showing "Revise & Resubmit" while a stage is actually open
        // with a reviewer (the old return_to_stage path never reset the status).
        $openInstanceDocIds = DB::table('document_workflow_instances as i')
            ->join('document_workflow_stage_runs as r', 'r.document_workflow_instance_id', '=', 'i.id')
            ->where('i.status', 'running')
            ->where('r.status', 'open')
            ->pluck('i.document_id');
        DB::table('documents')
            ->whereIn('id', $openInstanceDocIds)
            ->where('status', 'approved_with_changes_pending')
            ->update(['status' => 'in_review']);

        // Existing pending tasks get a due date from when they were assigned.
        DB::statement('UPDATE document_stage_assignees a
            LEFT JOIN workflow_stages s ON s.id = a.workflow_stage_id
            SET a.due_at = DATE_ADD(a.assigned_at, INTERVAL COALESCE(s.sla_hours, 48) HOUR)
            WHERE a.due_at IS NULL AND a.assigned_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('support_tickets');
        Schema::table('document_comments', fn (Blueprint $t) => $t->dropColumn(['replacement_text', 'edited_at']));
        Schema::dropIfExists('document_work_tasks');
        Schema::dropIfExists('document_stage_settings');
        Schema::table('document_stage_assignees', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reassigned_by');
            $t->dropConstrainedForeignId('reassigned_from_id');
            $t->dropColumn(['due_at', 'due_soon_notified_at', 'reassign_reason']);
        });
        DB::statement("UPDATE document_stage_assignees SET status = 'superseded' WHERE status = 'reassigned'");
        DB::statement("ALTER TABLE document_stage_assignees MODIFY status ENUM('pending', 'acted', 'skipped', 'superseded') NOT NULL DEFAULT 'pending'");
        Schema::table('workflow_templates', fn (Blueprint $t) => $t->dropColumn('awc_resume'));
        Schema::table('workflow_stages', fn (Blueprint $t) => $t->dropColumn('stage_type'));
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['channel', 'due_date', 'is_placeholder']));
        Schema::table('document_types', fn (Blueprint $t) => $t->dropColumn('channel'));
    }
};
