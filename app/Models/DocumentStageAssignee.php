<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentStageAssignee extends Model
{
    // Falls back to this when a stage doesn't have its own sla_hours configured.
    public const DEFAULT_SLA_HOURS = 48;

    protected $fillable = [
        'document_workflow_instance_id', 'workflow_stage_id', 'user_id',
        'reassigned_from_id', 'reassigned_by', 'reassign_reason',
        'status', 'assigned_at', 'due_at', 'acted_at', 'overdue_notified_at', 'due_soon_notified_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'due_at' => 'datetime',
        'acted_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
        'due_soon_notified_at' => 'datetime',
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

    public function reassignedBy()
    {
        return $this->belongsTo(User::class, 'reassigned_by');
    }

    public function reassignedFrom()
    {
        return $this->belongsTo(self::class, 'reassigned_from_id');
    }

    public function slaHours(): int
    {
        if ($this->due_at && $this->assigned_at) {
            return (int) round($this->assigned_at->diffInHours($this->due_at));
        }

        return $this->stage?->sla_hours ?? self::DEFAULT_SLA_HOURS;
    }

    /** When this task is due (falls back to assigned_at + SLA for older rows). */
    public function dueAt(): ?\Illuminate\Support\Carbon
    {
        return $this->due_at ?? $this->assigned_at?->copy()->addHours($this->slaHours());
    }

    /** Pending and due within the reminder window, but not yet overdue. */
    public function isDueSoon(): bool
    {
        $due = $this->dueAt();

        return $this->status === 'pending' && $due && $due->isFuture()
            && now()->diffInHours($due) <= config('promomats.due_dates.reminder_hours_before', 12);
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
        $due = $this->dueAt();

        return $this->status === 'pending' && $due && $due->isPast();
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
