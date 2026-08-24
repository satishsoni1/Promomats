<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Append-only. Rows are written exclusively through App\Services\Audit\AuditLogger
 * and are never edited or deleted from the UI (spec REQ: "audit records must not be
 * editable"). update()/delete() are blocked here as a backstop against a future
 * controller accidentally exposing a mutation route.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'document_id', 'document_version_id', 'document_workflow_instance_id',
        'workflow_stage_id', 'action', 'old_status', 'new_status', 'description',
        'ip_address', 'user_agent', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    // Human-readable labels for the actions this app currently emits. Any action
    // string not listed here just falls back to its raw value - this is display
    // sugar, not a closed enum, so new callers don't need a migration to log a
    // new kind of event.
    public const ACTION_LABELS = [
        'DOCUMENT_CREATED' => 'Document created',
        'DOCUMENT_UPDATED' => 'Document updated',
        'VERSION_CREATED' => 'New version uploaded',
        'SUBMITTED' => 'Submitted for review',
        'ASSIGNED' => 'Stage assigned',
        'SKIPPED' => 'Stage skipped (condition not met)',
        'SLA_BREACHED' => 'SLA breached',
        'VIEWED' => 'Viewed',
        'DOWNLOADED' => 'Downloaded',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Rejected',
        'REVISION_REQUESTED' => 'Revision requested',
        'RESUBMITTED' => 'Resubmitted',
        'FINAL_APPROVED' => 'Final approval',
        'DISTRIBUTED' => 'Approved for distribution',
        'DISTRIBUTION_CONFIRMED' => 'Distribution confirmed',
        'ARCHIVED' => 'Archived',
        'LEGAL_HOLD_PLACED' => 'Legal hold placed',
        'LEGAL_HOLD_RELEASED' => 'Legal hold released',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function instance()
    {
        return $this->belongsTo(DocumentWorkflowInstance::class, 'document_workflow_instance_id');
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? str($this->action)->headline();
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new RuntimeException('Audit log entries are append-only and cannot be updated.');
    }

    public function delete()
    {
        throw new RuntimeException('Audit log entries are append-only and cannot be deleted.');
    }
}
