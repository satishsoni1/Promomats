<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = ['name', 'code', 'division', 'description', 'status', 'created_by', 'updated_by'];

    /** @return array<string, string> division key => label */
    public static function divisions(): array
    {
        return collect(config('promomats.divisions', []))->map(fn ($d) => $d['label'])->all();
    }

    public function divisionLabel(): ?string
    {
        return static::divisions()[$this->division] ?? null;
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function skus()
    {
        return $this->hasMany(Sku::class)->orderBy('name');
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
