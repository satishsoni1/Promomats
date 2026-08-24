<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dedicated timestamp for "when did this document's status last change" - distinct
        // from updated_at, which also moves on unrelated edits (description tweaks, tag
        // changes, etc.) and so can't be trusted as the archiving clock. Set by a model
        // event whenever status is dirty (see Document::booted()).
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        // Backfill: best available proxy for existing rows is updated_at.
        DB::table('documents')->whereNull('status_changed_at')->update(['status_changed_at' => DB::raw('updated_at')]);

        // Single-row settings (same pattern as mail_settings/ai_settings). Off by default -
        // an admin has to explicitly opt in and set the window before anything auto-archives.
        // REQ-4.1's "355 days in the live system" is implemented here as days since the
        // document's status last settled into an approved/expired state (status_changed_at),
        // not days since creation - a draft sitting unsubmitted isn't "in the live system" yet.
        // This basis is a documented default, not dictated by the source requirement - flagged
        // as Open Question in the requirements doc; revisit if the intended trigger differs.
        Schema::create('archiving_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('days_before_archive')->default(355);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archiving_settings');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
