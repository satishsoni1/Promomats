<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $fillable = ['provider', 'api_key', 'model'];

    protected $casts = [
        'api_key' => 'encrypted',
    ];

    protected $hidden = ['api_key'];

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConfigured(): bool
    {
        return filled($this->api_key);
    }
}
