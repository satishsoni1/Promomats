<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Work handed to a whole team rather than one person - the internal Design Team
 * or the external Content Agency: "produce the creative from the aligned draft",
 * "artwork for this placeholder", or "rework after Not approved". Anyone in the
 * team can pick it up or assign it to one person; uploading the file completes
 * it. The Brand Manager always confirms the upload before review (re)starts,
 * except for Design Team rework the Brand Manager flagged to resubmit itself.
 */
class DocumentWorkTask extends Model
{
    public const TYPE_LABELS = [
        'artwork' => 'Artwork upload',
        'creative' => 'Creative from the aligned draft',
        'rework' => 'Rework after review',
    ];

    public const TEAM_LABELS = [
        'design' => 'Internal Design Team',
        'agency' => 'Content Agency',
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

    public function teamLabel(): string
    {
        return self::TEAM_LABELS[$this->team] ?? ucfirst($this->team);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_at && $this->due_at->isPast();
    }
}
