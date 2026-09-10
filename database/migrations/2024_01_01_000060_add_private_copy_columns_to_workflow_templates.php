<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A private, per-document copy of a shared workflow template - created when the
     * document owner adds / removes / reorders stages at upload time (only allowed
     * when the shared template has owner_can_customize_workflow = true). The copy
     * carries the one document's customised stage list so WorkflowEngine runs it
     * with no special-casing; it's kept out of the admin workflow list, the upload
     * form's template dropdown, and WorkflowResolver (is_private = true, and it's
     * created is_active = false as well).
     */
    public function up(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('owner_can_customize_workflow');
            $table->foreignId('derived_from_template_id')->nullable()->after('is_private')
                ->constrained('workflow_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('derived_from_template_id');
            $table->dropColumn('is_private');
        });
    }
};
