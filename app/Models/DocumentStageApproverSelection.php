<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One document owner's pick of "this named person takes this stage" for one
 * document - see the create_document_stage_approver_selections migration and
 * WorkflowEngine::resolveStageApprovers().
 */
class DocumentStageApproverSelection extends Model
{
    protected $fillable = ['document_id', 'workflow_stage_id', 'user_id'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
