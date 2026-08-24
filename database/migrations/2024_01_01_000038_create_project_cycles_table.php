<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Cycle is a round/iteration within a Project - e.g. "Q1 2026 Launch Wave",
     * "Cycle 1 - Initial Submission" - so a long-running project can track status
     * separately per iteration rather than lumping every document from every round
     * together. A document's cycle_id (next migration) is optional and, when set,
     * always belongs to the same project as the document's own project_id.
     */
    public function up(): void
    {
        Schema::create('project_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'completed', 'archived'])->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_cycles');
    }
};
