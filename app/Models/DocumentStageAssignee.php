<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentStageAssignee extends Model
{
    // Falls back to this when a stage doesn't have its own sla_hours configured.
    public const DEFAULT_SLA_HOURS = 48;

    protected $fillable = [
        'document_workflow_instance_id', 'workflow_stage_id', 'user_id',
        'status', 'assigned_at', 'acted_at', 'overdue_notified_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'acted_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
    ];

    public function instance()
    {
        return $this->belongsTo(DocumentWorkflowInstance::class, 'document_workflow_instance_id');
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function slaHours(): int
    {
        return $this->stage?->sla_hours ?? self::DEFAULT_SLA_HOURS;
    }

    public function hoursWaiting(): int
    {
        return (int) $this->assigned_at->diffInHours(now());
    }

    /**
     * The red-flag condition: still pending, and has been sitting longer than the
     * stage's SLA (or the 48-hour default if none is configured).
     */
    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->hoursWaiting() > $this->slaHours();
    }

    /**
     * True when this task was closed out not because this person acted, but
     * because the stage already resolved via someone else (e.g. an any_one
     * stage where a co-approver acted first). See
     * WorkflowEngine::resolveStageLocally().
     */
    public function isSuperseded(): bool
    {
        return $this->status === 'superseded';
    }
}
