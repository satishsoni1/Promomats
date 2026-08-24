<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_template_id')->constrained();
            $table->foreignId('current_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();

            $table->enum('status', ['running', 'approved', 'approved_with_changes', 'rejected', 'cancelled'])
                ->default('running');

            // If a stage sent this back for revision (AwC/NA), remember where to resume once revised content clears that stage again
            $table->foreignId('resume_at_stage_id')->nullable()->constrained('workflow_stages')->nullOnDelete();

            $table->foreignId('initiated_by')->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_workflow_instances');
    }
};
