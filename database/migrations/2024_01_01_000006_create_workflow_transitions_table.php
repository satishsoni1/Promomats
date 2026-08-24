<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Defines: at workflow_stage X, decision Y -> go to workflow_stage Z (or a terminal outcome)
        // This is what lets us reproduce the diagram's loops:
        //   Regulatory L1: Approved -> Regulatory L2
        //   Regulatory L1: Approved with Changes -> back to Content Manager (revision), then re-enters at Regulatory L1
        //   Regulatory L1: Not Approved -> back to Content Manager for rework, OR terminate, per business rule
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_stage_id')->constrained()->cascadeOnDelete(); // the "from" stage
            $table->enum('decision', ['approved', 'approved_with_changes', 'not_approved']);
            $table->enum('outcome_type', ['next_stage', 'return_to_stage', 'terminate_rejected', 'complete_approved'])->default('next_stage');
            $table->foreignId('target_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            // When outcome_type = return_to_stage, after the returned-to stage is re-approved,
            // resume_at_stage_id tells the engine where to continue from (usually back to the stage that sent it there).
            $table->foreignId('resume_at_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workflow_stage_id', 'decision'], 'uniq_stage_decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
    }
};
