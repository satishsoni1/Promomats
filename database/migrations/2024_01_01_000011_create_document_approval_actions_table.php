<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained();
            $table->foreignId('workflow_stage_id')->constrained();
            $table->foreignId('acted_by')->constrained('users');
            $table->enum('decision', ['approved', 'approved_with_changes', 'not_approved']);
            $table->text('comments')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('acted_at')->useCurrent();
            $table->timestamps();

            $table->index(['document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_approval_actions');
    }
};
