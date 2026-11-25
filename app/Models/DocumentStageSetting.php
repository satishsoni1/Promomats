<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The task owner's due period for one stage of one document (overrides the stage's
 * own sla_hours and the 48-hour default), and/or an exact due date/time the Brand
 * Manager set for it (due_at - used while it is still ahead when the stage opens).
 */
class DocumentStageSetting extends Model
{
    protected $fillable = ['document_id', 'workflow_stage_id', 'due_hours', 'due_at'];

    protected $casts = ['due_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function stage()
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }
}
