<?php

namespace App\Support;

use App\Models\User;

/**
 * The teams workflows and jobs are divided into (config/promomats.php 'teams'):
 * Pharma and Scientific Publications. Each has its own workflows and upload fields.
 */
class Teams
{
    public const DEFAULT = 'pharma';

    /** @return array<string, string> key => label */
    public static function labels(): array
    {
        return collect(config('promomats.teams', []))->map(fn ($t) => $t['label'] ?? '')->all();
    }

    public static function keys(): array
    {
        return array_keys(config('promomats.teams', [self::DEFAULT => []]));
    }

    public static function label(?string $team): string
    {
        return config("promomats.teams.{$team}.label") ?? ucfirst((string) $team);
    }

    /** Whether the upload form shows / stores $field for $team. */
    public static function hasField(?string $team, string $field): bool
    {
        return in_array($field, config('promomats.teams.' . ($team ?: self::DEFAULT) . '.fields', []), true);
    }

    /** The team the upload form starts on for this person (by their department). */
    public static function forUser(?User $user): string
    {
        foreach (config('promomats.teams', []) as $key => $team) {
            if ($user?->department && in_array($user->department, $team['departments'] ?? [], true)) {
                return $key;
            }
        }

        return self::DEFAULT;
    }
}
