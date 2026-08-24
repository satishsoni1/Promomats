<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Created automatically (status 'pending') the moment a workflow instance
     * completes into approved_for_distribution - see
     * WorkflowEngine::completeInstance(). A distribution manager then confirms
     * the actual channel/date, flipping it to 'distributed'. Distinct from the
     * document's own status: "approved for distribution" is a document-level
     * milestone, this table is the tracked event of it actually going out.
     */
    public function up(): void
    {
        Schema::create('distribution_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('distribution_date')->nullable();
            $table->string('distribution_channel')->nullable();
            $table->foreignId('distributed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'distributed', 'cancelled'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_records');
    }
};
