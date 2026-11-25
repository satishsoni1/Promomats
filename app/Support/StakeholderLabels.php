<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stakeholder names as Himalaya uses them: Medical -> R&D, Regulatory ->
 * Regulatory Affairs, Legal stays Legal. Renames what people see - role names,
 * workflow stage names and departments. Role slugs ('regulatory', 'legal', ...)
 * are left alone: the rules in config/promomats.php key off them. Where a separate
 * R&D ('rd') role exists, Medical is merged into it (see mergeMedicalIntoRnd()).
 *
 * Idempotent; run by migration 069 and again at the end of DatabaseSeeder (the
 * seeders still create the old names).
 */
class StakeholderLabels
{
    /** Role slug => display name. */
    public const ROLES = [
        'medical' => 'R&D',
        'regulatory' => 'Regulatory Affairs',
        'regulatory-level-1' => 'Regulatory Affairs Level 1',
        'regulatory-level-2' => 'Regulatory Affairs Level 2',
        'legal' => 'Legal',
    ];

    /** Department renames. */
    public const DEPARTMENTS = [
        'Medical' => 'R&D',
        'Regulatory' => 'Regulatory Affairs',
    ];

    public static function rename(string $text): string
    {
        $text = preg_replace('/\bMedical\b/u', 'R&D', $text);

        return preg_replace('/\bRegulatory\b(?! Affairs)/u', 'Regulatory Affairs', $text);
    }

    /**
     * Where an 'rd' (R&D) role already exists next to 'medical', they are the same
     * stakeholder: Medical's people, permissions and workflow stages move to R&D
     * (nobody loses access) and the Medical role is removed - the same way the old
     * Document Owner role was merged into Brand Manager (migration 065).
     */
    protected static function mergeMedicalIntoRnd(): void
    {
        $medical = DB::table('roles')->where('slug', 'medical')->value('id');
        $rnd = DB::table('roles')->where('slug', 'rd')->value('id');
        if (! $medical || ! $rnd) {
            return;
        }

        DB::transaction(function () use ($medical, $rnd) {
            foreach (DB::table('role_user')->where('role_id', $medical)->pluck('user_id') as $userId) {
                DB::table('role_user')->updateOrInsert(['role_id' => $rnd, 'user_id' => $userId]);
            }
            foreach (DB::table('permission_role')->where('role_id', $medical)->pluck('permission_id') as $permissionId) {
                DB::table('permission_role')->updateOrInsert(['role_id' => $rnd, 'permission_id' => $permissionId]);
            }
            foreach (DB::table('workflow_stage_approvers')->where('role_id', $medical)->get() as $row) {
                $hasRnd = DB::table('workflow_stage_approvers')->where('workflow_stage_id', $row->workflow_stage_id)->where('role_id', $rnd)->exists();
                $hasRnd
                    ? DB::table('workflow_stage_approvers')->where('id', $row->id)->delete()
                    : DB::table('workflow_stage_approvers')->where('id', $row->id)->update(['role_id' => $rnd]);
            }

            DB::table('role_user')->where('role_id', $medical)->delete();
            DB::table('permission_role')->where('role_id', $medical)->delete();
            DB::table('roles')->where('id', $medical)->delete();
        });
    }

    public static function apply(): void
    {
        if (Schema::hasTable('roles')) {
            self::mergeMedicalIntoRnd();

            foreach (self::ROLES as $slug => $name) {
                // Role names are unique: when another role already has the name
                // (e.g. a separate 'rd' role called R&D), keep it distinguishable.
                $taken = DB::table('roles')->where('name', $name)->where('slug', '!=', $slug)->exists();
                DB::table('roles')->where('slug', $slug)->update(['name' => $taken ? "{$name} ({$slug})" : $name]);
            }
        }

        if (Schema::hasTable('workflow_stages')) {
            DB::table('workflow_stages')
                ->where(fn ($q) => $q->where('name', 'like', '%Medical%')->orWhere('name', 'like', '%Regulatory%'))
                ->get(['id', 'name'])
                ->each(function ($stage) {
                    $new = self::rename($stage->name);
                    if ($new !== $stage->name) {
                        DB::table('workflow_stages')->where('id', $stage->id)->update(['name' => $new]);
                    }
                });
        }

        foreach ([['users', 'department'], ['users', 'admin_department'], ['workflow_rules', 'department'], ['workflow_templates', 'department']] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                foreach (self::DEPARTMENTS as $old => $new) {
                    DB::table($table)->where($column, $old)->update([$column => $new]);
                }
            }
        }
    }
}
