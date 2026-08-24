<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentApprovalAction extends Model
{
    protected $fillable = [
        'document_workflow_instance_id', 'document_id', 'document_version_id',
        'workflow_stage_id', 'acted_by', 'signed_name', 'decision', 'comments', 'ip_address', 'acted_at',
    ];

    protected $casts = ['acted_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function instance()
    {
        return $this->belongsTo(DocumentWorkflowInstance::class, 'document_workflow_instance_id');
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function decisionLabel(): string
    {
        return match ($this->decision) {
            'approved' => 'A - Approved',
            'approved_with_changes' => 'AwC - Approved with Changes',
            'not_approved' => 'NA - Not Approved',
        };
    }
}
