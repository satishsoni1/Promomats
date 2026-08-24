<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anchors a comment to an exact spot on a PDF page (the inline "sticky comment"
        // pattern) - null for the general, whole-document comment thread.
        Schema::table('document_comments', function (Blueprint $table) {
            $table->unsignedInteger('page_number')->nullable()->after('body');
            $table->decimal('x_position', 5, 2)->nullable()->after('page_number'); // 0-100, % of page width
            $table->decimal('y_position', 5, 2)->nullable()->after('x_position'); // 0-100, % of page height
        });
    }

    public function down(): void
    {
        Schema::table('document_comments', function (Blueprint $table) {
            $table->dropColumn(['page_number', 'x_position', 'y_position']);
        });
    }
};
