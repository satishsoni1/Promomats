<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'description', 'category', 'brand_id', 'document_type_id', 'products', 'countries', 'target_audience', 'reference_no', 'owner_id', 'workflow_template_id',
        'allow_department_editing',
        'project_id', 'cycle_id', 'status', 'start_date', 'expiry_date', 'aging_warning_days',
        'is_expired', 'is_aging_flagged', 'current_version_id',
        'legal_hold', 'legal_hold_reason', 'legal_hold_set_by', 'legal_hold_set_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'expiry_date' => 'date',
        'status_changed_at' => 'datetime',
        'is_expired' => 'boolean',
        'is_aging_flagged' => 'boolean',
        'products' => 'array',
        'countries' => 'array',
        'legal_hold' => 'boolean',
        'legal_hold_set_at' => 'datetime',
        'allow_department_editing' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Dedicated clock for "how long has this document sat in its current status" -
        // updated_at moves on any edit, which would make the archiving policy (REQ-4.1)
        // fire based on unrelated changes rather than actual status age.
        static::saving(function (Document $document) {
            if ($document->isDirty('status')) {
                $document->status_changed_at = now();
            }
        });
    }

    // Statuses eligible for the automated archiving policy (REQ-4.1) - anything that has
    // left an active workflow. Draft/In Review/Revise & Resubmit are deliberately excluded:
    // a document still moving through approval isn't "in the live system" in the sense the
    // policy means, regardless of how long it's been sitting.
    public const ARCHIVABLE_STATUSES = [
        'approved', 'approved_for_production', 'approved_for_distribution',
        'pending_expiration', 'expired', 'rejected', 'superseded', 'obsolete',
    ];

    public const STATUS_LABELS = [
        'draft' => 'Draft',
        'in_review' => 'In Review',
        'approved_with_changes_pending' => 'Revise & Resubmit',
        'rejected' => 'Rejected',
        'approved' => 'Approved',
        'approved_for_production' => 'Approved for Production',
        'approved_for_distribution' => 'Approved for Distribution',
        'pending_expiration' => 'Pending Expiration',
        'expired' => 'Expired',
        'superseded' => 'Superseded',
        'obsolete' => 'Obsolete',
        'archived' => 'Archived',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function workflowTemplate()
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function distributionRecords()
    {
        return $this->hasMany(DistributionRecord::class)->latest();
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function cycle()
    {
        return $this->belongsTo(ProjectCycle::class, 'cycle_id');
    }

    public function legalHoldSetBy()
    {
        return $this->belongsTo(User::class, 'legal_hold_set_by');
    }

    public function legalHoldEvents()
    {
        return $this->hasMany(DocumentLegalHold::class)->latest('created_at');
    }

    /**
     * Blocks the version/status-changing actions a legal hold is meant to freeze:
     * new content (uploadNewVersion, PdfEditController::store) and any manual or
     * automatic transition into an archived/retired status (updateStatus,
     * ApplyArchivingPolicy). Deliberately does NOT block metadata bookkeeping
     * (project/cycle assignment, comments, observers) or in-flight approval
     * decisions - a hold freezes the record, it doesn't stop reviewers already
     * mid-workflow from finishing what's already in front of them.
     */
    public function assertNotOnLegalHold(): void
    {
        if ($this->legal_hold) {
            throw ValidationException::withMessages([
                'legal_hold' => "This document is under legal hold and cannot be archived or have its content changed. Reason: {$this->legal_hold_reason}",
            ]);
        }
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_no');
    }

    public function currentVersion()
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function workflowInstances()
    {
        return $this->hasMany(DocumentWorkflowInstance::class);
    }

    public function activeWorkflowInstance()
    {
        return $this->hasOne(DocumentWorkflowInstance::class)->where('status', 'running')->latestOfMany();
    }

    public function approvalActions()
    {
        return $this->hasMany(DocumentApprovalAction::class)->orderByDesc('acted_at');
    }

    /**
     * The document owner's per-stage "send this stage to these named people" picks,
     * made at upload time when the workflow template allows it (see
     * WorkflowTemplate::$owner_can_customize_workflow). Empty for a document that
     * just uses its template's approver pools unchanged.
     */
    public function stageApproverSelections()
    {
        return $this->hasMany(DocumentStageApproverSelection::class);
    }

    /**
     * User IDs this document's owner pinned to the given stage, or null when they
     * made no pick for it (in which case the engine falls back to the stage's own
     * role/user approver rules). Never returns an empty collection - "no pick" and
     * "picked nobody" are the same thing here.
     *
     * @return \Illuminate\Support\Collection<int, int>|null
     */
    public function approverIdsForStage(WorkflowStage $stage)
    {
        $ids = ($this->relationLoaded('stageApproverSelections')
                ? $this->stageApproverSelections
                : $this->stageApproverSelections())
            ->where('workflow_stage_id', $stage->id)
            ->pluck('user_id')
            ->unique()
            ->values();

        return $ids->isNotEmpty() ? $ids : null;
    }

    /**
     * True when this document's owner has switched on "open editing" and $user is in
     * the same department as the owner - the extra branch DocumentPolicy adds on top
     * of owner / admin / Agency. Deliberately department-of-the-owner, not of the
     * document (documents carry no department of their own).
     */
    public function openToDepartmentEditor(User $user): bool
    {
        return $this->allow_department_editing
            && filled($user->department)
            && $user->department === ($this->relationLoaded('owner') ? $this->owner?->department : $this->owner()->value('department'));
    }

    public function watchers()
    {
        return $this->belongsToMany(User::class, 'document_watchers');
    }

    /**
     * General, whole-document comment thread (not anchored to a PDF page/position).
     */
    public function comments()
    {
        return $this->hasMany(DocumentComment::class)
            ->whereNull('parent_id')
            ->whereNull('page_number')
            ->whereNull('timestamp_seconds')
            ->with(['author', 'replies.author'])
            ->latest();
    }

    /**
     * Comments anchored to a specific page/position on the current PDF version - the
     * inline "sticky comment" pins.
     */
    public function pdfAnnotations()
    {
        return $this->hasMany(DocumentComment::class)
            ->whereNull('parent_id')
            ->whereNotNull('page_number')
            ->with(['author', 'replies.author'])
            ->oldest();
    }

    /**
     * REQ-2.1: comments anchored to a moment in time on the current video version -
     * the video analogue of pdfAnnotations() above, same table/model, a timestamp
     * instead of a page/x/y.
     */
    public function videoAnnotations()
    {
        return $this->hasMany(DocumentComment::class)
            ->whereNull('parent_id')
            ->whereNotNull('timestamp_seconds')
            ->with(['author', 'replies.author'])
            ->oldest();
    }

    /**
     * Uploaded reference files (source PDFs, studies, certificates) attached directly to
     * this document - distinct from claims and from a claim's own text-only citations.
     */
    public function referenceAttachments()
    {
        return $this->morphMany(ReferenceAttachment::class, 'attachable')->latest();
    }

    /**
     * REQ-3.3: interactive claim <-> reference mappings pinned to a specific text
     * span on a PDF page.
     */
    public function claimReferenceMappings()
    {
        return $this->hasMany(ClaimReferenceMapping::class)->with(['claim', 'referenceAttachment', 'creator']);
    }

    /**
     * REQ-4.3: requests to restore this document's files from cold storage back onto
     * primary storage.
     */
    public function retrievalRequests()
    {
        return $this->hasMany(RetrievalRequest::class)->latest();
    }

    /**
     * True once any of this document's version files have been migrated to the cold
     * storage disk (REQ-4.2) - the trigger for showing the "Request Retrieval" action.
     */
    public function isOnColdStorage(): bool
    {
        return $this->versions->contains(fn ($v) => $v->disk === 'cold_storage');
    }

    /**
     * AI-suggested claim candidates awaiting human review (REQ-3.1) - separate from
     * the claims already inserted into the document via claims()/document_claim.
     */
    public function claimCandidates()
    {
        return $this->hasMany(ClaimCandidate::class)->latest();
    }

    /**
     * Claims inserted into this document (the claims-library "where used" link).
     */
    public function claims()
    {
        return $this->belongsToMany(Claim::class, 'document_claim')
            ->withPivot(['document_version_id', 'inserted_by'])
            ->withTimestamps();
    }

    // --- lifecycle flag helpers ---

    public function getDaysToExpiryAttribute(): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false);
    }

    public function computeIsExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function computeIsAging(): bool
    {
        if (! $this->expiry_date || $this->computeIsExpired()) {
            return false;
        }

        return $this->days_to_expiry !== null && $this->days_to_expiry <= $this->aging_warning_days;
    }

    public function computeIsNotYetStarted(): bool
    {
        return $this->start_date && $this->start_date->isFuture();
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? str($this->status)->headline();
    }

    public function targetAudienceLabel(): ?string
    {
        return \App\Support\TargetAudience::label($this->target_audience);
    }
}
