<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = ['name', 'code', 'description', 'target_audience', 'lead_id', 'status', 'created_by'];

    public function targetAudienceLabel(): ?string
    {
        return \App\Support\TargetAudience::label($this->target_audience);
    }

    public function lead()
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Rounds/iterations within this project - e.g. "Q1 2026 Launch Wave". A document
     * assigned to a cycle is also always assigned to that cycle's project.
     */
    public function cycles()
    {
        return $this->hasMany(ProjectCycle::class)->orderByDesc('created_at');
    }

    /**
     * Project-level reference library: files uploaded directly to the project rather
     * than to any one document - the "project-wise centralized library" upload path.
     */
    public function referenceAttachments()
    {
        return $this->morphMany(ReferenceAttachment::class, 'attachable')->latest();
    }

    /**
     * Broad status buckets for the dashboard - 12 granular statuses (Document::STATUS_LABELS)
     * is too much detail for an at-a-glance summary, so this collapses them into 5 groups
     * that map to what someone scanning a dashboard actually wants to know: is this
     * moving, stuck, done, or needs attention.
     */
    public const STATUS_BUCKETS = [
        'draft' => ['draft'],
        'in_review' => ['in_review'],
        'needs_attention' => ['approved_with_changes_pending', 'rejected'],
        'approved' => ['approved', 'approved_for_production', 'approved_for_distribution'],
        'expiring' => ['pending_expiration', 'expired'],
        'retired' => ['superseded', 'obsolete', 'archived'],
    ];

    public const BUCKET_LABELS = [
        'draft' => 'Draft',
        'in_review' => 'In Review',
        'needs_attention' => 'Needs Attention',
        'approved' => 'Approved',
        'expiring' => 'Expiring/Expired',
        'retired' => 'Retired',
    ];

    /**
     * Document counts grouped into the buckets above, keyed by bucket, defaulting
     * every bucket to 0 so the dashboard never has to guard against a missing key.
     */
    public function statusBreakdown(): array
    {
        $counts = $this->documents()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $breakdown = array_fill_keys(array_keys(self::STATUS_BUCKETS), 0);
        foreach (self::STATUS_BUCKETS as $bucket => $statuses) {
            foreach ($statuses as $status) {
                $breakdown[$bucket] += (int) ($counts[$status] ?? 0);
            }
        }

        return $breakdown;
    }
}
