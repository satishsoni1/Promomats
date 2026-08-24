<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec REQ-53: a stage can be gated on the document it's evaluating -
     * "only run Medical Review if target_audience = hcp", "skip this stage
     * unless document_type = VIDEO". Null means unconditional (always runs,
     * the existing default behavior for every stage created before this).
     * See WorkflowStage::conditionMatches().
     */
    public function up(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->json('condition_json')->nullable()->after('quorum_count');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn('condition_json');
        });
    }
};
