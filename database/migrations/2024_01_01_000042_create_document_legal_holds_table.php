<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanent audit history of every legal-hold place/release on a document - kept
     * separate from the "current state" columns on documents (added in the previous
     * migration) so releasing a hold never loses the record that it happened, who did
     * it, and why. Surfaced in the Document History Report timeline alongside
     * versions/signatures/edits.
     */
    public function up(): void
    {
        Schema::create('document_legal_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->enum('action', ['placed', 'released']);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_legal_holds');
    }
};
