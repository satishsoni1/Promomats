<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legal hold: a compliance officer/admin can freeze a document mid-litigation or
     * regulatory inquiry so its content can't change and it can't be archived/retired
     * out from under an active investigation - a standard requirement in pharma
     * document-control systems (and a common e-discovery/FDA-audit expectation).
     * These four columns are the "current state" read on every guarded action; the
     * full place/release history lives in document_legal_holds (next migration).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('legal_hold')->default(false)->after('is_aging_flagged');
            $table->text('legal_hold_reason')->nullable()->after('legal_hold');
            $table->foreignId('legal_hold_set_by')->nullable()->after('legal_hold_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('legal_hold_set_at')->nullable()->after('legal_hold_set_by');
            $table->index('legal_hold');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_hold_set_by');
            $table->dropColumn(['legal_hold', 'legal_hold_reason', 'legal_hold_set_at']);
        });
    }
};
