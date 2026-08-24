<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTransition extends Model
{
    protected $fillable = [
        'workflow_stage_id', 'decision', 'outcome_type', 'target_stage_id', 'resume_at_stage_id',
    ];

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function targetStage()
    {
        return $this->belongsTo(WorkflowStage::class, 'target_stage_id');
    }

    public function resumeAtStage()
    {
        return $this->belongsTo(WorkflowStage::class, 'resume_at_stage_id');
    }
}
