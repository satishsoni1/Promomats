<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REQ-3.3: interactive links between a specific highlighted text span inside a
     * PDF and the claim/reference file that substantiates it. Deliberately a
     * separate table from document_comments (the free-text pin/thread model) -
     * a mapping isn't a comment, it's a structured pointer: page/x/y position
     * (same anchoring convention as the existing PDF pins) plus the selected text,
     * a claim, and/or a reference attachment.
     */
    public function up(): void
    {
        Schema::create('claim_reference_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_id')->nullable()->constrained('claims')->nullOnDelete();
            $table->foreignId('reference_attachment_id')->nullable()->constrained('reference_attachments')->nullOnDelete();
            $table->unsignedInteger('page_number');
            $table->decimal('x_position', 6, 2);
            $table->decimal('y_position', 6, 2);
            $table->string('selected_text', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['document_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_reference_mappings');
    }
};
