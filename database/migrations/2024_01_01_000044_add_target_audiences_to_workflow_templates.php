<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which target audiences (App\Support\TargetAudience keys) this template is
        // recommended for, admin-tagged. Lets the document create form auto-suggest a
        // matching workflow once the user picks an audience, without hard-coding
        // assumptions about which templates exist.
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->json('target_audiences')->nullable()->after('applies_to_category');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->dropColumn('target_audiences');
        });
    }
};
