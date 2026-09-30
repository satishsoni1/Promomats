<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-platform PDF review: a comment can anchor to a text selection (the
        // highlighted words + one box per line, as page percentages) rather than
        // just a point, can be resolved once handled, and remembers which version
        // it was made on so pins carried over onto a later version are labelled.
        Schema::table('document_comments', function (Blueprint $table) {
            $table->text('selected_text')->nullable()->after('y_position');
            $table->json('highlight_rects')->nullable()->after('selected_text'); // [{x,y,w,h}] 0-100
            $table->foreignId('document_version_id')->nullable()->after('document_id')->constrained()->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('timestamp_seconds');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropConstrainedForeignId('document_version_id');
            $table->dropColumn(['selected_text', 'highlight_rects', 'resolved_at']);
        });
    }
};
