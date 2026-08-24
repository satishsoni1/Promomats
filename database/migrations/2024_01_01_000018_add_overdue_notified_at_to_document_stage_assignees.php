<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_stage_assignees', function (Blueprint $table) {
            // Set once an overdue reminder has been sent for this assignment, so the
            // scheduled command doesn't re-notify on every run - just once per assignment.
            $table->timestamp('overdue_notified_at')->nullable()->after('acted_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_stage_assignees', function (Blueprint $table) {
            $table->dropColumn('overdue_notified_at');
        });
    }
};
