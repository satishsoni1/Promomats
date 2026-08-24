<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetrievalRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const SLA_HOURS = 24;

    protected $fillable = [
        'document_id', 'requested_by', 'status', 'requested_at', 'sla_due_at', 'completed_at', 'notes',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * True once the SLA clock has run out on a request that isn't done yet - or, for
     * a completed one, if it finished after its deadline.
     */
    public function isOverdue(): bool
    {
        if (in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS])) {
            return now()->greaterThan($this->sla_due_at);
        }

        if ($this->status === self::STATUS_COMPLETED) {
            return $this->completed_at?->greaterThan($this->sla_due_at) ?? false;
        }

        return false;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst($this->status),
        };
    }
}
