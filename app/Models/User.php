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
