<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // e.g. "Pharma Workflow 1", "Pharma Workflow 2", "Pharma Workflow 3 (Adaptations of Approved Content)"
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique(); // WF1, WF2, WF3
            $table->text('description')->nullable();
            $table->string('applies_to_category')->nullable(); // e.g. category of documents this workflow governs
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_templates');
    }
};
