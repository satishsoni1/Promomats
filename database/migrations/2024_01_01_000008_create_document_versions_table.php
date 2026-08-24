<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_no'); // 1, 2, 3...
            $table->string('version_label')->nullable(); // e.g. "v1.0", "Rev A" - optional human label

            $table->string('original_filename');
            $table->string('disk')->default('documents'); // local, s3, etc — configurable per deployment
            $table->string('file_path'); // path on the disk
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable(); // integrity + duplicate detection

            $table->text('change_notes')->nullable(); // what changed vs previous version (esp. after AwC)
            $table->foreignId('uploaded_by')->constrained('users');

            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['document_id', 'version_no']);
        });

        // now that document_versions exists, wire the FK from documents.current_version_id
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('document_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('document_versions');
    }
};
