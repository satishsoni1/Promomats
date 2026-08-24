<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentWorkflowInstance extends Model
{
    protected $fillable = [
        'document_id', 'document_version_id', 'workflow_template_id', 'current_stage_id',
        'status', 'resume_at_stage_id', 'initiated_by', 'started_at', 'completed_at',
    ];

    protected $casts = ['started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function template()
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    public function currentStage()
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
    }

    public function resumeAtStage()
    {
        return $this->belongsTo(WorkflowStage::class, 'resume_at_stage_id');
    }

    public function assignees()
    {
        return $this->hasMany(DocumentStageAssignee::class);
    }

    public function pendingAssignees()
    {
        return $this->assignees()->where('status', 'pending');
    }

    public function actions()
    {
        return $this->hasMany(DocumentApprovalAction::class);
    }

    public function stageRuns()
    {
        return $this->hasMany(DocumentWorkflowStageRun::class);
    }

    /**
     * Every stage currently in progress for this instance - a single stage
     * normally, or every member of a parallel_group while some of them are
     * still waiting on a decision. Drives the "Workflow Status" panel and lets
     * the engine tell whether a whole group has finished (see
     * WorkflowEngine::recordDecision()).
     */
    public function openStageRuns()
    {
        return $this->stageRuns()->where('status', 'open')->with('stage');
    }

    /**
     * Everyone who should be notified about activity on this document's
     * workflow: document owner, initiator, watchers, and current pending
     * approvers. Used by Listeners\Workflow\SendWorkflowNotifications - moved
     * here (out of WorkflowEngine) so it's reachable from a listener without
     * the listener needing to depend on the engine service itself.
     */
    public function stakeholderIds(): array
    {
        return collect([$this->document->owner_id, $this->initiated_by])
            ->merge($this->document->watchers()->pluck('users.id'))
            ->merge($this->pendingAssignees()->pluck('user_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
