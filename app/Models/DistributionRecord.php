<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistributionRecord extends Model
{
    protected $fillable = [
        'document_id', 'document_version_id', 'approved_by', 'approved_at',
        'distribution_date', 'distribution_channel', 'distributed_by', 'status',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'distribution_date' => 'date',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function distributedBy()
    {
        return $this->belongsTo(User::class, 'distributed_by');
    }
}
