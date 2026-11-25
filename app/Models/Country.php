<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Country master (list supplied by Himalaya) - the document's Country dropdown. */
class Country extends Model
{
    protected $fillable = ['name', 'code', 'status'];

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Two-letter prefix for document reference numbers (code, else the name's first letters). */
    public function referencePrefix(): string
    {
        $source = $this->code ?: $this->name;

        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $source), 0, 2)) ?: 'GL';
    }
}
