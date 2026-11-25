<?php

use App\Support\StakeholderLabels;
use Illuminate\Database\Migrations\Migration;

/**
 * Medical -> R&D, Regulatory -> Regulatory Affairs (Legal unchanged) in role
 * names, workflow stage names and departments. Slugs are not touched.
 * Not reversed on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        StakeholderLabels::apply();
    }

    public function down(): void
    {
        // Labels only - nothing to undo.
    }
};
