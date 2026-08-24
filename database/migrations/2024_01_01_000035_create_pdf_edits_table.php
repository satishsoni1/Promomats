<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real, in-place PDF content edits (via pdf-lib in the browser - see
     * resources/js/pdf-editor.js): each row is one attributed, timestamped edit
     * operation (added text or a redacted/removed region) that got baked into a new
     * document version's actual PDF bytes. The edit itself lives permanently in the
     * saved PDF (as a highlighted region); this table is the structured, queryable
     * record of who made it and what it was, surfaced in the approval workflow
     * alongside Review Actions.
     */
    public function up(): void
    {
        Schema::create('pdf_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->enum('edit_type', ['add_text', 'redact']);
            $table->unsignedInteger('page_number');
            $table->decimal('x', 6, 2);
            $table->decimal('y', 6, 2);
            $table->decimal('width', 6, 2)->nullable();
            $table->decimal('height', 6, 2)->nullable();
            $table->text('content')->nullable();
            $table->timestamps();

            $table->index('document_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_edits');
    }
};
