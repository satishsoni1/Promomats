<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-instance, per-stage "is this stage's current pass still open, and if
        // not, what did it resolve to" tracker. This is what lets the engine know
        // when *every* member of a parallel_group has resolved (fan-in) without
        // having to guess which document_approval_actions rows belong to the
        // current pass vs. an earlier revision loop through the same stage. One
        // row per (instance, stage) - re-entering a stage after a revision loop
        // updates the existing row in place rather than appending a new one; the
        // full historical record already lives in document_approval_actions.
        Schema::create('document_workflow_stage_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_workflow_instance_id')
                ->constrained('document_workflow_instances', 'id', 'doc_wf_stage_runs_instance_id_fk')
                ->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')
                ->constrained('workflow_stages', 'id', 'doc_wf_stage_runs_stage_id_fk')
                ->cascadeOnDelete();
            $table->enum('status', ['open', 'resolved'])->default('open');
            $table->string('decision')->nullable(); // approved | approved_with_changes | not_approved
            $table->timestamp('entered_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['document_workflow_instance_id', 'workflow_stage_id'], 'doc_wf_stage_runs_instance_stage_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_workflow_stage_runs');
    }
};
