<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ColdStorageSetting extends Model
{
    protected $fillable = [
        'enabled', 'key', 'secret', 'region', 'bucket', 'endpoint',
        'use_path_style_endpoint', 'days_after_archive',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'secret' => 'encrypted',
        'use_path_style_endpoint' => 'boolean',
        'days_after_archive' => 'integer',
    ];

    protected $hidden = ['secret'];

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConfigured(): bool
    {
        return filled($this->key) && filled($this->secret) && filled($this->bucket);
    }

    /**
     * Builds a Flysystem-shaped disk config array from these settings, matching
     * config/filesystems.php's 's3' disk shape - consumed by
     * ColdStorageConfigServiceProvider to register the 'cold_storage' disk at
     * runtime, and reusable directly for a one-off Test Connection check.
     */
    public function toDiskConfig(): array
    {
        return [
            'driver' => 's3',
            'key' => $this->key,
            'secret' => $this->secret,
            'region' => $this->region ?: 'us-east-1',
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint ?: null,
            'use_path_style_endpoint' => $this->use_path_style_endpoint,
            'throw' => true,
            'report' => false,
        ];
    }
}
