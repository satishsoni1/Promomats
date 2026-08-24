<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaimReferenceMapping extends Model
{
    protected $fillable = [
        'document_id', 'claim_id', 'reference_attachment_id', 'page_number',
        'x_position', 'y_position', 'selected_text', 'created_by',
    ];

    protected $casts = [
        'x_position' => 'float',
        'y_position' => 'float',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function claim()
    {
        return $this->belongsTo(Claim::class);
    }

    public function referenceAttachment()
    {
        return $this->belongsTo(ReferenceAttachment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
