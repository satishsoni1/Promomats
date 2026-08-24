<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cross-team Projects: a self-serve grouping any user can create (no admin gate)
     * to organize documents and a shared reference library across teams working on
     * the same initiative - e.g. "Immunobooster Q3 Launch". Deliberately lightweight:
     * no membership/permission model of its own, since documents are already visible
     * system-wide (see REQ-1.1) - a project is an organizing label, not an access
     * boundary.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable(); // short reference prefix, e.g. "IMB-Q3"
            $table->text('description')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
