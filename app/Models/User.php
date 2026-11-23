<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'employee_code', 'email', 'department', 'admin_department', 'designation',
        'password', 'is_active', 'must_change_password', 'created_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'password' => 'hashed',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    public function hasAnyRole(array $slugs): bool
    {
        return $this->roles->pluck('slug')->intersect($slugs)->isNotEmpty();
    }

    public function hasPermission(string $permissionSlug): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('slug', $permissionSlug))
            ->exists();
    }

    /** MLR reviewer (Medical / Regulatory / Legal): reviews and comments, never uploads. */
    public function isMlrReviewer(): bool
    {
        return $this->hasAnyRole(config('promomats.roles.mlr', ['medical', 'regulatory', 'legal', 'regulatory-level-1', 'regulatory-level-2', 'legal-level-1', 'legal-level-2']));
    }

    /** Member of the internal Design Team (also acts as Content Creator in workflows). */
    public function isDesignTeam(): bool
    {
        return $this->hasAnyRole(config('promomats.roles.design_team', ['design-team', 'design-internal', 'content-creator', 'content-creator-intext']));
    }

    /**
     * A Brand Manager who holds no reviewer role: may start jobs, upload and assign
     * stakeholders, but never approve or reject (UAT feedback). Someone who is both
     * a Brand Manager and e.g. a Regulatory reviewer can still act in that capacity.
     */
    public function isBrandManagerOnly(): bool
    {
        $roleSlugs = $this->roles->pluck('slug');

        return $roleSlugs->intersect(config('promomats.roles.brand_manager', ['brand-manager']))->isNotEmpty()
            && $roleSlugs->diff(config('promomats.roles.brand_manager', ['brand-manager']))->isEmpty();
    }

    /** Whether this person may ever record an approval decision. */
    public function canRecordDecisions(): bool
    {
        return ! $this->isBrandManagerOnly();
    }

    /**
     * Whether this person may upload files at all (new jobs, new versions). MLR
     * reviewers are review-only unless they also hold a non-MLR role, or are admins.
     */
    public function canUploadDocuments(): bool
    {
        if ($this->isGlobalAdmin()) {
            return true;
        }

        $roleSlugs = $this->roles->pluck('slug');

        return $roleSlugs->isEmpty() || $roleSlugs->diff(config('promomats.roles.mlr', ['medical', 'regulatory', 'legal', 'regulatory-level-1', 'regulatory-level-2', 'legal-level-1', 'legal-level-2']))->isNotEmpty();
    }

    /** Active Design Team members. */
    public static function designTeam()
    {
        return static::teamMembers('design');
    }

    /** External Content Agency user. */
    public function isAgency(): bool
    {
        return $this->hasAnyRole(config('promomats.roles.agency', ['content-agency', 'agency']));
    }

    /** Whether this person belongs to a work-task team ('design' or 'agency'). */
    public function inTeam(string $team): bool
    {
        return $team === 'agency' ? $this->isAgency() : $this->isDesignTeam();
    }

    /** Active members of a work-task team: 'design' (internal Design Team) or 'agency'. */
    public static function teamMembers(string $team)
    {
        $slugs = $team === 'agency'
            ? config('promomats.roles.agency', ['content-agency', 'agency'])
            : config('promomats.roles.design_team', ['design-team', 'design-internal', 'content-creator', 'content-creator-intext']);

        return static::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', $slugs))
            ->orderBy('name');
    }

    /** Full, unscoped administrator. */
    public function isGlobalAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /** Admin whose reach is limited to one department (see admin_department). */
    public function isDepartmentAdmin(): bool
    {
        return ! $this->isGlobalAdmin()
            && $this->hasRole('department-admin')
            && filled($this->admin_department);
    }

    /** Can reach the /admin area at all (global admin, or a scoped department admin). */
    public function canAdminister(): bool
    {
        return $this->isGlobalAdmin() || $this->isDepartmentAdmin();
    }

    /**
     * The department this user's admin powers are limited to, or null when they're a
     * global admin (no limit). Callers scope their queries by this: null => no filter.
     */
    public function adminDepartmentScope(): ?string
    {
        return $this->isGlobalAdmin() ? null : ($this->admin_department ?: null);
    }

    /** Whether this admin may act on something belonging to $department. */
    public function adminCanReachDepartment(?string $department): bool
    {
        $scope = $this->adminDepartmentScope();

        return $scope === null || $scope === $department;
    }

    public function ownedDocuments()
    {
        return $this->hasMany(Document::class, 'owner_id');
    }

    public function pendingApprovals()
    {
        return $this->hasMany(DocumentStageAssignee::class, 'user_id')->where('status', 'pending');
    }

    /**
     * Open team work this person can act on: tasks assigned to them, plus anything
     * still sitting unassigned with a team they belong to (Design Team / Agency).
     */
    public function openWorkTasksQuery()
    {
        return DocumentWorkTask::open()->where(function ($q) {
            $q->where('assigned_to', $this->id);
            foreach (['design', 'agency'] as $team) {
                if ($this->inTeam($team)) {
                    $q->orWhere(fn ($q2) => $q2->where('team', $team)->whereNull('assigned_to'));
                }
            }
        });
    }

    /**
     * Everything waiting on this person's action - approvals, their own jobs that
     * are back with them (revision needed, or draft aligned and awaiting the
     * creative), and team work - for the sidebar badge.
     */
    public function actionRequiredCount(): int
    {
        return $this->pendingApprovals()->count()
            + $this->ownedDocuments()->whereIn('status', Document::OWNER_ACTION_STATUSES)
                ->whereDoesntHave('workTasks', fn ($q) => $q->open())->count()
            + $this->openWorkTasksQuery()->count();
    }

    /**
     * Every decision this user has electronically signed (21 CFR Part 11), across
     * all documents - their personal signing history for the personal dashboard.
     */
    public function approvalActionsTaken()
    {
        return $this->hasMany(DocumentApprovalAction::class, 'acted_by');
    }

    public function ledProjects()
    {
        return $this->hasMany(Project::class, 'lead_id');
    }
}
