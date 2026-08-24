<?php

namespace App\Providers;

use App\Mail\Transport\MicrosoftGraphTransport;
use App\Models\MailSetting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Applies admin-configured mail settings (Admin > Mail Settings) over whatever
 * .env/config/mail.php says, so an admin can point outbound mail at Gmail SMTP (or
 * any SMTP provider) from the UI without editing files or redeploying. Silently
 * no-ops until the migration has run and an admin has actually filled in a host -
 * until then, mail keeps using .env's MAIL_MAILER (default 'log') exactly as before.
 *
 * Also registers the 'graph' mail transport (Microsoft Graph /sendMail, app-only
 * OAuth2) regardless of which mailer ends up selected - that registration is cheap
 * and doesn't touch the network, so it's fine to always make it available.
 */
class MailConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('graph', function () {
            $config = config('mail.mailers.graph');

            return new MicrosoftGraphTransport(
                tenantId: $config['tenant_id'],
                clientId: $config['client_id'],
                clientSecret: $config['client_secret'],
                sender: $config['sender'],
                ccAll: $config['cc_all'] ?? null,
            );
        });

        try {
            if (! Schema::hasTable('mail_settings')) {
                return;
            }

            $settings = MailSetting::query()->first();

            if (! $settings || ! $settings->isConfigured()) {
                return;
            }

            config([
                'mail.default' => $settings->mailer ?: 'smtp',
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => $settings->port ?: 587,
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password,
                'mail.mailers.smtp.encryption' => $settings->encryption ?: 'tls',
                'mail.from.address' => $settings->from_address ?: config('mail.from.address'),
                'mail.from.name' => $settings->from_name ?: config('mail.from.name'),
            ]);
        } catch (\Throwable $e) {
            // DB not reachable yet (fresh install, migrating, etc.) - fall back to .env silently.
        }
    }
}
