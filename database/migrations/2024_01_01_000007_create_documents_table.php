<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable(); // e.g. Promotional Material, Adaptation, etc.
            $table->string('reference_no')->unique(); // auto-generated doc number

            $table->foreignId('owner_id')->constrained('users'); // Document Owner
            $table->foreignId('workflow_template_id')->nullable()->constrained();

            $table->enum('status', [
                'draft',            // uploaded, not yet submitted
                'in_review',        // moving through workflow stages
                'approved_with_changes_pending', // waiting on revision upload after AwC
                'rejected',         // terminated as Not Approved
                'approved',         // fully approved for production
                'approved_for_distribution', // final stage passed
                'expired',
                'archived',
            ])->default('draft');

            // Lifecycle flags requested: start date, expiry date, aging
            $table->date('start_date')->nullable();   // date document/material becomes valid/usable
            $table->date('expiry_date')->nullable();  // date after which it must be flagged expired
            $table->unsignedInteger('aging_warning_days')->default(30); // flag "aging" when within N days of expiry
            $table->boolean('is_expired')->default(false);   // maintained by scheduled job
            $table->boolean('is_aging_flagged')->default(false); // maintained by scheduled job

            $table->foreignId('current_version_id')->nullable(); // FK added after document_versions exists
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
