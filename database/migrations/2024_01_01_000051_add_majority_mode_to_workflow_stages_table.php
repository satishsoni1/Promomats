<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Third approval mode alongside any_one/all_required: 'majority' resolves a
     * stage once more than half its assigned approvers have approved, or once
     * approval is mathematically impossible given who's left to act (see
     * WorkflowEngine::resolveMajorityStage()). `quorum_count`, when set, overrides
     * the computed simple-majority threshold with an exact "N of M" requirement -
     * e.g. "5 reviewers, 3 approvals required" doesn't have to equal a strict
     * majority of whoever happens to be assigned.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE workflow_stages MODIFY approval_mode ENUM('any_one', 'all_required', 'majority') DEFAULT 'any_one'");

        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->unsignedInteger('quorum_count')->nullable()->after('approval_mode');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn('quorum_count');
        });

        DB::statement("ALTER TABLE workflow_stages MODIFY approval_mode ENUM('any_one', 'all_required') DEFAULT 'any_one'");
    }
};
