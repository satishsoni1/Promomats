<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflows and jobs are divided by team (see config/promomats.php 'teams'):
 * Pharma (promotional material - brand, SKU, country, collateral...) and Scientific
 * Publications (its own workflows, and its own fields over time). The upload form
 * offers each team only its own workflows and fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->string('team', 30)->default('pharma')->after('department')->index();
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->string('team', 30)->default('pharma')->after('owner_id')->index();
        });

        DB::table('workflow_templates')
            ->where(fn ($q) => $q->where('family_code', 'like', 'SCIPUB%')
                ->orWhere('code', 'like', 'SCIPUB%')
                ->orWhere('department', 'Scientific Publications'))
            ->update(['team' => 'publication']);

        // Private per-job copies follow the template they were copied from.
        DB::statement("UPDATE workflow_templates c JOIN workflow_templates p ON p.id = c.derived_from_template_id SET c.team = p.team WHERE c.derived_from_template_id IS NOT NULL");

        DB::statement("UPDATE documents d JOIN workflow_templates t ON t.id = d.workflow_template_id SET d.team = t.team");
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('team'));
        Schema::table('workflow_templates', fn (Blueprint $t) => $t->dropColumn('team'));
    }
};
