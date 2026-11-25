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
        'document_id', 'type', 'team', 'content_agency_id', 'status', 'assigned_to', 'requested_by', 'instructions',
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

    /** Which content agency the work went to (team 'agency'). */
    public function contentAgency()
    {
        return $this->belongsTo(ContentAgency::class);
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

    /**
     * Agency work an agency user may act on: their own agency's tasks (work sent
     * before agencies were named, with no agency set, stays visible to every agency
     * user as before).
     */
    public function scopeForAgencyOf($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->whereNull('content_agency_id');
            if ($user->content_agency_id) {
                $q->orWhere('content_agency_id', $user->content_agency_id);
            }
        });
    }

    /** Whether $user is in the team this task went to (for agency work: the same agency). */
    public function isForTeamOf(User $user): bool
    {
        if (! $user->inTeam($this->team)) {
            return false;
        }

        return $this->team !== 'agency'
            || $this->content_agency_id === null
            || $this->content_agency_id === $user->content_agency_id;
    }

    /** The people this task can be handed to: its team, or for agency work its agency. */
    public function teamMembers()
    {
        return User::teamMembers($this->team, $this->team === 'agency' ? $this->content_agency_id : null);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucfirst($this->type);
    }

    public function teamLabel(): string
    {
        $label = self::TEAM_LABELS[$this->team] ?? ucfirst($this->team);

        return $this->team === 'agency' && $this->contentAgency ? "{$label} ({$this->contentAgency->name})" : $label;
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_at && $this->due_at->isPast();
    }
}
