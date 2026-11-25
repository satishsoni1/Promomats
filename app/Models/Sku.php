<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A SKU under a brand (Brand & SKU master supplied by Himalaya). Replaces the old
 * free-text "Product" field: the document's SKU dropdown is filtered by its brand.
 */
class Sku extends Model
{
    protected $fillable = ['brand_id', 'name', 'code', 'status', 'created_by'];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
