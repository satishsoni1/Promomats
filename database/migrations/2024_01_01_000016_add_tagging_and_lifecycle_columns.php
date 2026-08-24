<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Multi-product / multi-country tagging (a single asset can apply to several
            // products/countries at once) - additive, alongside the existing single
            // 'category' field rather than replacing it.
            $table->json('products')->nullable()->after('category');
            $table->json('countries')->nullable()->after('products');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->json('products')->nullable()->after('product');
            $table->json('countries')->nullable()->after('country');
        });

        // Widen the document lifecycle to match PromoMats-style granularity: a document
        // can be "approved for production" before it's ready for full distribution, can
        // be flagged as nearing expiry (distinct from the boolean is_aging_flagged, which
        // stays as the machine-readable flag the scheduled command checks), and can be
        // manually retired as superseded/obsolete after distribution.
        DB::statement("ALTER TABLE documents MODIFY status ENUM(
            'draft', 'in_review', 'approved_with_changes_pending', 'rejected',
            'approved', 'approved_for_production', 'approved_for_distribution',
            'pending_expiration', 'expired', 'superseded', 'obsolete', 'archived'
        ) DEFAULT 'draft'");

        // Rules attached to a content module (e.g. "the graph and text asset must be
        // used together") - the lightweight equivalent of PromoMats' module rules.
        Schema::create('content_module_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_module_id')->constrained()->cascadeOnDelete();
            $table->text('rule_text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_module_rules');

        DB::statement("ALTER TABLE documents MODIFY status ENUM(
            'draft', 'in_review', 'approved_with_changes_pending', 'rejected',
            'approved', 'approved_for_distribution', 'expired', 'archived'
        ) DEFAULT 'draft'");

        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['products', 'countries']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['products', 'countries']);
        });
    }
};
