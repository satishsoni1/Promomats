<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable on purpose: existing documents (and the free-text `category` field
     * they already have) keep working unchanged. Brand/Document Type only become
     * required in practice once an admin has actually populated those masters and
     * a document is created through the updated form.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('category')->constrained()->nullOnDelete();
            $table->foreignId('document_type_id')->nullable()->after('brand_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('document_type_id');
        });
    }
};
