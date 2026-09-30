<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Work handed to a whole team rather than one person - e.g. "Design Team: produce
 * the artwork for this placeholder" or "Design Team: rework after Approved with
 * changes". Anyone in the team can pick it up or assign it to one designer
 * (config('promomats.roles.design_team')); uploading the file completes it.
 */
class DocumentWorkTask extends Model
{
    public const TYPE_LABELS = [
        'artwork' => 'Artwork upload',
        'rework' => 'Rework after review',
    ];

    protected $fillable = [
        'document_id', 'type', 'team', 'status', 'assigned_to', 'requested_by', 'instructions',
        'resubmit_on_upload', 'due_at', 'completed_version_id', 'completed_by', 'completed_at',
    ];

    protected $casts = [
        'resubmit_on_upload' => 'boolean',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucfirst($this->type);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_at && $this->due_at->isPast();
    }
}
