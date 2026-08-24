<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 21 CFR Part 11 electronic signature manifestation: the signer's printed name
     * captured *at the moment of signing*, independent of the linked user account
     * (acted_by) - if that user later changes their display name, this historical
     * record still reads exactly as it was signed. Paired with the password
     * re-authentication now required in DocumentApprovalController::act() before a
     * decision is ever recorded (being logged in is not, on its own, a signature).
     */
    public function up(): void
    {
        Schema::table('document_approval_actions', function (Blueprint $table) {
            $table->string('signed_name')->nullable()->after('acted_by');
        });
    }

    public function down(): void
    {
        Schema::table('document_approval_actions', function (Blueprint $table) {
            $table->dropColumn('signed_name');
        });
    }
};
