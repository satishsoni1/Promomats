<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI-proposed claims extracted from a document's text, awaiting human review
     * before they become real entries in the Claims library (REQ-3.1). Kept as a
     * distinct queue from Claim itself so a rejected/low-quality suggestion never
     * pollutes the approved library or needs a destructive delete to undo.
     */
    public function up(): void
    {
        Schema::create('claim_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('suggested_text', 1000);
            $table->string('suggested_category')->nullable();
            $table->decimal('ai_confidence', 3, 2)->nullable(); // 0.00 - 1.00
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_claim_id')->nullable()->constrained('claims')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_candidates');
    }
};
