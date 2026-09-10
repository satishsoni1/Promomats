<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Give the document owner the right to edit the flow, or not" (per template).
     *
     * When true, the document create/upload form lets the owner pick which specific
     * person(s) take each stage from that stage's configured candidate list - useful
     * when a stage is wired to a pool ("any Project Lead", "either Legal approver")
     * and this particular document should go to one named person. When false the
     * owner just sees the resolved flow; the engine uses the template's full pool as
     * before. The picks themselves live in document_stage_approver_selections.
     */
    public function up(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->boolean('owner_can_customize_workflow')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->dropColumn('owner_can_customize_workflow');
        });
    }
};
