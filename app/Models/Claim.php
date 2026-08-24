<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Claim extends Model
{
    protected $fillable = [
        'match_text', 'body', 'category', 'product', 'country', 'products', 'countries', 'language',
        'status', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'products' => 'array',
        'countries' => 'array',
    ];

    public function references()
    {
        return $this->hasMany(ClaimReference::class);
    }

    /**
     * Uploaded reference files (source PDFs, studies) attached to this claim - distinct
     * from the text-only citations above (title/citation/URL, no file).
     */
    public function referenceAttachments()
    {
        return $this->morphMany(ReferenceAttachment::class, 'attachable')->latest();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Every document this claim has been inserted into ("Where Used").
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_claim')
            ->withPivot(['document_version_id', 'inserted_by'])
            ->withTimestamps();
    }

    public function contentModules(): BelongsToMany
    {
        return $this->belongsToMany(ContentModule::class, 'content_module_claim')->withTimestamps();
    }
}
