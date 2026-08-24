<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence_no'); // order within the workflow
            $table->string('name'); // e.g. "Content Manager Review", "Regulatory Level 1"
            $table->string('code'); // machine key e.g. CONTENT_MGR, REG_L1, REG_L2, LEGAL_L1, LEGAL_L2, DOC_OWNER, TM_AGM, R_AND_D, CHAIRPERSON
            $table->enum('approval_mode', ['any_one', 'all_required'])->default('any_one');
            // any_one: first assigned approver's decision moves the stage forward (common for single-role stages)
            // all_required: every assigned approver at this stage must act before the stage resolves
            $table->boolean('is_revision_stage')->default(false); // stages like "Content Manager" that content returns to after AwC/NA
            $table->boolean('is_final_distribution_stage')->default(false); // marks document as "Approved for Distribution"
            $table->unsignedInteger('sla_hours')->nullable(); // optional SLA before escalation/aging flag
            $table->timestamps();

            $table->unique(['workflow_template_id', 'sequence_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stages');
    }
};
