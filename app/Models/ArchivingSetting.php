<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchivingSetting extends Model
{
    protected $fillable = ['enabled', 'days_before_archive'];

    protected $casts = [
        'enabled' => 'boolean',
        'days_before_archive' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
