<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStageApprover extends Model
{
    protected $fillable = ['workflow_stage_id', 'role_id', 'user_id'];

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve this approver rule to a concrete list of user IDs.
     */
    public function resolveUserIds(): array
    {
        if ($this->user_id) {
            return [$this->user_id];
        }

        if ($this->role_id) {
            return $this->role?->users()->pluck('users.id')->toArray() ?? [];
        }

        return [];
    }
}
