<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REQ-2.3: an uploaded reference *file* (a source PDF, a study, a certificate),
        // distinct from the citation-only text references a Claim already carries
        // (claim_references - title/citation/URL, no file). Polymorphic so both a
        // Document and a Claim can carry attached reference files through one table
        // rather than duplicating the same structure twice.
        Schema::create('reference_attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable'); // attachable_type, attachable_id (Document or Claim)
            $table->string('title');
            $table->string('disk')->default('documents');
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_attachments');
    }
};
