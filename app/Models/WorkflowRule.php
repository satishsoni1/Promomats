<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowRule extends Model
{
    protected $fillable = [
        'workflow_template_id', 'brand_id', 'document_type_id', 'department',
        'priority', 'status', 'created_by',
    ];

    public function template()
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * How many of the three match dimensions this rule actually pins down (vs.
     * leaving as a wildcard). Used as the tiebreaker when two active rules share
     * a priority - the more specific rule should still win.
     */
    public function specificity(): int
    {
        return (int) ($this->brand_id !== null) + (int) ($this->document_type_id !== null) + (int) ($this->department !== null);
    }
}
