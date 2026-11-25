<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Stakeholder groups a team's job must reach (Pharma: at least one R&D, one Legal
 * and one Regulatory Affairs reviewer). A person counts for a group through their
 * roles - see config('promomats.workflow.required_stakeholders').
 */
class RequiredStakeholders
{
    /** @return array<string, array<int, string>> group label => role slugs */
    public static function groups(?string $team): array
    {
        return config('promomats.workflow.required_stakeholders.' . ($team ?? Teams::DEFAULT), []);
    }

    /** @return array<int, string> the group labels a user counts for */
    public static function groupsOf(User $user, ?string $team): array
    {
        $slugs = $user->roles->pluck('slug');

        return collect(static::groups($team))
            ->filter(fn ($roles) => $slugs->intersect($roles)->isNotEmpty())
            ->keys()->all();
    }

    /**
     * The groups none of $userIds covers.
     *
     * @param  iterable<int, int>  $userIds
     * @return array<int, string>
     */
    public static function missing(?string $team, iterable $userIds): array
    {
        $groups = static::groups($team);
        if ($groups === []) {
            return [];
        }

        $covered = User::with('roles')->whereIn('id', collect($userIds)->unique()->all())->get()
            ->flatMap(fn (User $u) => static::groupsOf($u, $team))->unique();

        return array_values(array_diff(array_keys($groups), $covered->all()));
    }

    /**
     * For the upload form: user id => groups, and role id => groups.
     *
     * @return array{users: array<int, array<int, string>>, roles: array<int, array<int, string>>}
     */
    public static function lookup(?string $team): array
    {
        $groups = static::groups($team);
        $slugs = collect($groups)->flatten()->unique()->all();
        if ($slugs === []) {
            return ['users' => [], 'roles' => []];
        }

        $users = User::with('roles')->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', $slugs))->get()
            ->mapWithKeys(fn (User $u) => [$u->id => static::groupsOf($u, $team)]);

        $roles = Role::whereIn('slug', $slugs)->get()
            ->mapWithKeys(fn (Role $r) => [$r->id => collect($groups)->filter(fn ($s) => in_array($r->slug, $s, true))->keys()->all()]);

        return ['users' => $users->all(), 'roles' => $roles->all()];
    }

    /** "R&D, Legal and Regulatory Affairs" */
    public static function sentence(array $labels): string
    {
        return collect($labels)->join(', ', ' and ');
    }
}
