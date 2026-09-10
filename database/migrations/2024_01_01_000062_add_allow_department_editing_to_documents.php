<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-document "open editing" toggle. Off by default: only the owner, an admin
     * and Agency users can edit metadata / upload new versions. When the owner turns
     * it on, anyone in the owner's department can too (see DocumentPolicy). Deciding
     * whether to submit a version into the workflow stays owner/admin only.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('allow_department_editing')->default(false)->after('workflow_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('allow_department_editing');
        });
    }
};
