<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REQ-2.1: video annotation pins. Generalises the same anchored-comment pattern
     * already used for PDF page/x/y pins (document_comments.page_number/x_position/
     * y_position) - a video pin just anchors to a moment in time instead of a spot on
     * a page, so it reuses the same table/model rather than a parallel one.
     */
    public function up(): void
    {
        Schema::table('document_comments', function (Blueprint $table) {
            $table->decimal('timestamp_seconds', 10, 3)->nullable()->after('y_position');
        });
    }

    public function down(): void
    {
        Schema::table('document_comments', function (Blueprint $table) {
            $table->dropColumn('timestamp_seconds');
        });
    }
};
