<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Bearer token for the mobile app. Only a SHA-256 hash is stored; the plain token
 * is shown to the app once, at login.
 */
class ApiToken extends Model
{
    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at', 'expires_at'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{0: self, 1: string} the stored token and its plain-text value
     */
    public static function issue(User $user, string $name): array
    {
        $plain = Str::random(64);

        $token = static::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(config('promomats.mobile.token_days')),
        ]);

        return [$token, $plain];
    }

    public static function findValid(string $plain): ?self
    {
        $token = static::where('token_hash', hash('sha256', $plain))->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return null;
        }

        return $token;
    }
}
