<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A permanent, auditable record of every AI-assisted action taken on a document
        // (compliance pre-check, revision-drafting suggestion) - who ran it, what model,
        // what it found/suggested. Kept separate from document_comments since these are
        // AI-generated, not a person's own words, and reviewers should be able to see
        // "was this checked by AI, and what did it say" independent of the comment thread.
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->enum('type', ['compliance_check', 'revision_suggestion']);
            $table->string('model')->nullable();
            $table->longText('output'); // the model's response (markdown/plain text)
            $table->string('risk_level')->nullable(); // low | medium | high, compliance_check only
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
