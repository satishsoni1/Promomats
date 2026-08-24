<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaimCandidate extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'document_id', 'suggested_text', 'suggested_category', 'ai_confidence',
        'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'created_claim_id',
    ];

    protected $casts = [
        'ai_confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdClaim()
    {
        return $this->belongsTo(Claim::class, 'created_claim_id');
    }

    public function confidencePercent(): ?int
    {
        return $this->ai_confidence === null ? null : (int) round($this->ai_confidence * 100);
    }
}
