<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A reusable, named bundle of claims (a "modular content" building block) that
        // content creators assemble once and reuse across documents - e.g. a standard
        // "Efficacy Block" every leaflet for a product pulls in wholesale.
        Schema::create('content_modules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('product')->nullable();
            $table->string('country')->nullable();
            $table->enum('status', ['draft', 'approved'])->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('content_module_claim', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence_no')->default(1);
            $table->timestamps();

            $table->unique(['content_module_id', 'claim_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_module_claim');
        Schema::dropIfExists('content_modules');
    }
};
