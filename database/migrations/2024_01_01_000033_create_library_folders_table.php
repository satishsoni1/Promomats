<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Full folder/subfolder hierarchy for the Reference Library - a self-serve file
     * system independent of any one document/claim/project, so teams can organize
     * shared material (study archives, templates, certificates) the way they'd
     * organize a shared drive. Files placed here (reference_attachments.library_folder_id,
     * see the next migration) remain reusable into any document/claim/project via the
     * existing copy-on-attach flow (ReferenceLibraryService).
     */
    public function up(): void
    {
        Schema::create('library_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('library_folders')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_folders');
    }
};
