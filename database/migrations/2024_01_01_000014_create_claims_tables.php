<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A reusable, pre-approved claim (short substantiated statement) that can be
        // inserted into any number of documents - the "claims library" pattern from
        // PromoMats. Kept independent of the document workflow: a claim is approved
        // once here, then reused everywhere, rather than re-litigated per document.
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('match_text'); // the short claim text as it appears in content
            $table->text('body')->nullable(); // fuller substantiation text, if different from match_text
            $table->string('category')->nullable(); // efficacy, safety, dosing, ...
            $table->string('product')->nullable();
            $table->string('country')->nullable();
            $table->string('language', 10)->default('en');
            $table->enum('status', ['draft', 'approved', 'expired'])->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'category']);
        });

        // Source references a claim is substantiated by (citation text + optional URL/document).
        Schema::create('claim_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('citation')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
        });

        // "Where used": every place a claim has been inserted into a document (version-
        // pinned so history survives even if the claim text later changes).
        Schema::create('document_claim', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inserted_by')->constrained('users');
            $table->timestamps();

            $table->unique(['document_id', 'claim_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_claim');
        Schema::dropIfExists('claim_references');
        Schema::dropIfExists('claims');
    }
};
