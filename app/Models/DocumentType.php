<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $fillable = ['name', 'code', 'allowed_extensions', 'max_file_size_kb', 'status'];

    protected $casts = ['allowed_extensions' => 'array'];

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

    public function acceptsExtension(string $extension): bool
    {
        if (empty($this->allowed_extensions)) {
            return true; // no restriction configured
        }

        return in_array(strtolower(ltrim($extension, '.')), array_map('strtolower', $this->allowed_extensions), true);
    }

    public function acceptsFileSize(int $bytes): bool
    {
        if (! $this->max_file_size_kb) {
            return true; // no restriction configured
        }

        return $bytes <= ($this->max_file_size_kb * 1024);
    }
}
