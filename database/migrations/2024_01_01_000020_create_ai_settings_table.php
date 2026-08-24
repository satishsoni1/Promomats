<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row settings (always id=1), same pattern as mail_settings: an admin
        // pastes an API key from the UI and every AI feature activates immediately -
        // no .env edits, no redeploy. Blank api_key = AI features gracefully disabled
        // (compliance check / claim matching / revision suggestions / AI search all
        // hide or fall back to their non-AI behavior).
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('anthropic');
            $table->text('api_key')->nullable(); // encrypted at rest, see AiSetting::api_key cast
            $table->string('model')->default('claude-sonnet-5');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
