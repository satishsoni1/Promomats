<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The "generic, not hardcoded" piece: which workflow a document gets is data
     * here, not an if/else in PHP. A null brand_id/document_type_id/department on
     * a rule means "matches any" (a wildcard), so a single fallback rule can cover
     * everything else. See App\Services\Workflow\WorkflowResolver.
     */
    public function up(): void
    {
        Schema::create('workflow_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('department')->nullable();
            // Lower number = evaluated first = higher priority. Lets an admin layer
            // a specific rule (brand X + type Y) above a general fallback (type Y only).
            $table->integer('priority')->default(100);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_rules');
    }
};
