<?php

namespace App\Providers;

use App\Models\ColdStorageSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the 'cold_storage' filesystem disk at runtime from Admin > Cold
 * Storage Settings (REQ-4.2), the same pattern MailConfigServiceProvider uses for
 * SMTP. Generic S3-compatible config, so this works unmodified against AWS S3,
 * Backblaze B2, Wasabi, MinIO, etc. - whatever bucket/endpoint the admin enters.
 * Silently no-ops (leaving config/filesystems.php's local-disk stub in place)
 * until the migration has run and an admin has actually configured + enabled it.
 */
class ColdStorageConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            if (! Schema::hasTable('cold_storage_settings')) {
                return;
            }

            $settings = ColdStorageSetting::query()->first();

            if (! $settings || ! $settings->enabled || ! $settings->isConfigured()) {
                return;
            }

            config(['filesystems.disks.cold_storage' => $settings->toDiskConfig()]);
        } catch (\Throwable $e) {
            // DB not reachable yet (fresh install, migrating, etc.) - fall back to the local stub disk silently.
        }
    }
}
