<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Department-scoped administration. A user with the "Department Admin" role and
     * a non-null admin_department can reach the admin area, but only manages Users,
     * Workflows and dashboard data for that one department. The global "Admin" role
     * is unchanged and unscoped. workflow_templates.department is the scoping key on
     * the workflow side (null = a shared/global template, visible only to global
     * admins for editing).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_department')->nullable()->after('department');
        });

        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->string('department')->nullable()->after('applies_to_category');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_department');
        });

        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }
};
