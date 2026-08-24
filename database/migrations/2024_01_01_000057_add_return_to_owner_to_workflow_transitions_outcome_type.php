<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'return_to_owner': AwC/NA parks the document (status =
     * approved_with_changes_pending) and notifies the document owner to
     * revise and upload a new version, without re-entering any stage - unlike
     * the existing 'return_to_stage', which immediately re-opens a "revision
     * hub" gate. See WorkflowEngine::resolveStage().
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE workflow_transitions MODIFY outcome_type ENUM('next_stage', 'return_to_stage', 'return_to_owner', 'terminate_rejected', 'complete_approved') NOT NULL DEFAULT 'next_stage'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE workflow_transitions MODIFY outcome_type ENUM('next_stage', 'return_to_stage', 'terminate_rejected', 'complete_approved') NOT NULL DEFAULT 'next_stage'");
    }
};
