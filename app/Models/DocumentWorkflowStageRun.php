<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-instance, per-stage "is this stage's current pass still open, and what did
 * it resolve to" tracker - see the migration for why this exists (fan-in for
 * parallel_group stages). One row per (instance, stage); re-entering a stage
 * after a revision loop updates the row in place rather than appending.
 */
class DocumentWorkflowStageRun extends Model
{
    protected $fillable = [
        'document_workflow_instance_id', 'workflow_stage_id', 'status', 'decision', 'entered_at', 'resolved_at',
    ];

    protected $casts = [
        'entered_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function instance()
    {
        return $this->belongsTo(DocumentWorkflowInstance::class, 'document_workflow_instance_id');
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }
}
