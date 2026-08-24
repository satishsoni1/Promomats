<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-row settings table for the cold storage tier (REQ-4.2), following the
     * same pattern as mail_settings/ai_settings/archiving_settings. Deliberately
     * generic S3-compatible config (key/secret/region/bucket/endpoint/path-style)
     * rather than an AWS-specific one - works unmodified with AWS S3, Backblaze B2,
     * Wasabi, MinIO, or any other S3-API-compatible provider, so the admin picks
     * the vendor rather than the code.
     */
    public function up(): void
    {
        Schema::create('cold_storage_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('key')->nullable();
            $table->text('secret')->nullable();
            $table->string('region')->nullable();
            $table->string('bucket')->nullable();
            $table->string('endpoint')->nullable(); // e.g. https://s3.us-west-002.backblazeb2.com; blank = AWS default
            $table->boolean('use_path_style_endpoint')->default(true);
            $table->integer('days_after_archive')->default(30); // how long a doc stays "archived" on hot storage before migration
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cold_storage_settings');
    }
};
