<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-document override of "who takes this stage", chosen by the document owner
     * at upload time when the workflow template has owner_can_customize_workflow = true.
     *
     * Each row pins one user to one stage for one document. WorkflowEngine::enterStage
     * consults these first and only falls back to the stage's own role/user approver
     * rules when a document has no selection for that stage. A user picked here is
     * always validated against the stage's configured candidates first - the owner can
     * narrow a pool down to a named person, never bring in someone the template didn't
     * already allow.
     */
    public function up(): void
    {
        Schema::create('document_stage_approver_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            $table->unique(['document_id', 'workflow_stage_id', 'user_id'], 'dsas_doc_stage_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_stage_approver_selections');
    }
};
