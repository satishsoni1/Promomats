<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stages sharing the same non-null parallel_group (within one template) are
        // entered together and run concurrently - the fan-out/fan-in needed for
        // e.g. MLR Medical/Regulatory/Legal reviewing the same version at the same
        // time instead of queued one after another. Null (the default) means "runs
        // alone", identical to today's behaviour.
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->string('parallel_group')->nullable()->after('sequence_no');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn('parallel_group');
        });
    }
};
