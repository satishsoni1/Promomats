<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who the content is ultimately for (patient, HCP, payer, ...) - see
        // App\Support\TargetAudience for the vocabulary. Nullable/advisory: it drives an
        // inline compliance hint and a suggested workflow template on the create form,
        // it doesn't gate anything by itself.
        Schema::table('documents', function (Blueprint $table) {
            $table->string('target_audience')->nullable()->after('countries');
        });

        // A project-level default that documents created under it inherit (and can
        // still override individually).
        Schema::table('projects', function (Blueprint $table) {
            $table->string('target_audience')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('target_audience');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('target_audience');
        });
    }
};
