<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = ['name', 'code', 'description', 'status', 'created_by', 'updated_by'];

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function workflowRules()
    {
        return $this->hasMany(WorkflowRule::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
