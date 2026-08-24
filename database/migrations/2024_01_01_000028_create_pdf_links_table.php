<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REQ-2.4: embedded hyperlinks extracted straight from a PDF's own Link
     * annotation objects (the /Annots -> /A -> /URI structure - a different,
     * structured layer from the plain text smalot/pdfparser already reads),
     * optionally matched to one of the document's reference attachments (REQ-2.3).
     */
    public function up(): void
    {
        Schema::create('pdf_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('uri', 2048);
            $table->string('rect')->nullable(); // "x1,y1,x2,y2" in PDF page units, for a future overlay UI
            $table->foreignId('matched_reference_attachment_id')->nullable()->constrained('reference_attachments')->nullOnDelete();
            $table->enum('status', ['unmatched', 'matched', 'ignored'])->default('unmatched');
            $table->timestamps();

            $table->index(['document_version_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_links');
    }
};
