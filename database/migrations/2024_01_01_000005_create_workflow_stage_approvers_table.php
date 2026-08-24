<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stage_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_stage_id')->constrained()->cascadeOnDelete();
            // Either tied to a role (dynamic - whoever holds that role at run time)
            // or a specific user (for cases like "Chairperson's Office" being one named person)
            $table->foreignId('role_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stage_approvers');
    }
};
