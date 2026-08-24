<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A single-row settings table (always id=1) letting an admin configure outbound
        // mail (e.g. Gmail SMTP) from the UI instead of editing .env. When empty/host is
        // null, the app falls back to whatever MAIL_MAILER is set to in .env (default:
        // 'log', so nothing breaks before an admin fills this in).
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mailer')->default('smtp');
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted at rest, see MailSetting::password cast
            $table->string('encryption')->nullable(); // tls, ssl, or null
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
