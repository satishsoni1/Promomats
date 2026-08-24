<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Publish/lock/version-forward for workflow templates. `family_code` groups
     * every version of "the same" workflow (e.g. all versions of "Pharma
     * Workflow 1" share one family_code even though each version is its own row
     * with its own immutable `code`); `version` orders them. A template is
     * considered locked (see WorkflowTemplate::isLocked()) once any document has
     * actually started a workflow instance against it - from that point its
     * stages/transitions can no longer be edited in place, only version-forward
     * via WorkflowTemplate::createNewVersion().
     */
    public function up(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->string('family_code')->nullable()->after('code');
            $table->unsignedInteger('version')->default(1)->after('family_code');
        });

        // Backfill: every pre-existing template is version 1 of its own family.
        DB::table('workflow_templates')->update(['family_code' => DB::raw('code')]);

        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->unique(['family_code', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->dropUnique(['family_code', 'version']);
            $table->dropColumn(['family_code', 'version']);
        });
    }
};
