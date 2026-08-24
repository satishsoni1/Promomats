<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds 'superseded' to document_stage_assignees.status: when a stage resolves
     * (e.g. an any_one stage where one of several people acted, or a majority/
     * all_required stage that resolved early), every other still-pending assignee
     * at that same stage is closed out with this status instead of being left
     * 'pending' forever - that stale-forever-pending gap is what made it look like
     * nobody could tell who had actually approved on behalf of the group.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE document_stage_assignees MODIFY status ENUM('pending', 'acted', 'skipped', 'superseded') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE document_stage_assignees SET status = 'skipped' WHERE status = 'superseded'");
        DB::statement("ALTER TABLE document_stage_assignees MODIFY status ENUM('pending', 'acted', 'skipped') NOT NULL DEFAULT 'pending'");
    }
};
