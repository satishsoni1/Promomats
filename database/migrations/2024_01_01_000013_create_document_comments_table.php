<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // General discussion thread on a document (independent of the approve/reject
        // decision comments in document_approval_actions) - any user who can view the
        // document can post a comment or reply to one, similar to PromoMats' inline
        // comment threads.
        Schema::create('document_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('document_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->text('body');
            $table->timestamps();

            $table->index(['document_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_comments');
    }
};
