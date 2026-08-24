<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cross-team Reference Library: an optional category (mirrors Claim::category)
     * so the library index can be filtered - "Study", "Legal Template", "Certificate",
     * etc. - useful once references are browsable across every team's documents/claims
     * rather than only ever seen inside the one document they were first uploaded to.
     */
    public function up(): void
    {
        Schema::table('reference_attachments', function (Blueprint $table) {
            $table->string('category')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('reference_attachments', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
