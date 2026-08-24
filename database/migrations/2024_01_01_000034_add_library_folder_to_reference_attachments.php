<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A library file is a ReferenceAttachment that stands on its own - not (yet)
     * substantiation for a specific Document/Claim/Project, just organized into a
     * folder (or the library root, library_folder_id null). attachable_type/id must
     * become nullable to allow that; raw ALTER (not ->change()) to avoid adding
     * doctrine/dbal as a dependency just for this one column-nullability change.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE reference_attachments MODIFY attachable_type VARCHAR(255) NULL');
        DB::statement('ALTER TABLE reference_attachments MODIFY attachable_id BIGINT UNSIGNED NULL');

        Schema::table('reference_attachments', function (Blueprint $table) {
            $table->foreignId('library_folder_id')->nullable()->after('attachable_id')->constrained('library_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reference_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('library_folder_id');
        });

        DB::statement('ALTER TABLE reference_attachments MODIFY attachable_type VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE reference_attachments MODIFY attachable_id BIGINT UNSIGNED NOT NULL');
    }
};
