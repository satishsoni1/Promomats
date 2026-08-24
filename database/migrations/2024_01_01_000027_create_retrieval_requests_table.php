<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * REQ-4.3: on-demand retrieval of a document's files back from cold storage,
     * with a standard 24-hour SLA (sla_due_at = requested_at + 24h). Assumption
     * (Open Question 5 in the PRD): the 24h SLA covers full restoration, not just
     * acknowledgement - so completed_at vs sla_due_at is what "met/missed the SLA"
     * means in this system.
     */
    public function up(): void
    {
        Schema::create('retrieval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending');
            // Nullable at the DB level only to sidestep MySQL's "only one TIMESTAMP
            // column may auto-default" restriction (strict mode rejects a second
            // NOT NULL timestamp with no default) - the app always sets both on create.
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_requests');
    }
};
