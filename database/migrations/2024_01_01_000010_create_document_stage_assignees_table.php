<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a workflow instance enters a stage, the engine resolves the stage's
        // role/user rules into concrete pending assignees here. This is what each
        // user's "pending approvals" inbox is queried from.
        Schema::create('document_stage_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->enum('status', ['pending', 'acted', 'skipped'])->default('pending');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_stage_assignees');
    }
};
