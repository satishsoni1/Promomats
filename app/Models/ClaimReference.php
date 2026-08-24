<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaimReference extends Model
{
    protected $fillable = ['claim_id', 'title', 'citation', 'url'];

    public function claim()
    {
        return $this->belongsTo(Claim::class);
    }
}
