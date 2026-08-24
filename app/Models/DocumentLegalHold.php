<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentLegalHold extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['document_id', 'action', 'reason', 'actor_id'];

    protected $casts = ['created_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
