<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContentModule extends Model
{
    protected $fillable = ['name', 'description', 'product', 'country', 'status', 'created_by'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function claims(): BelongsToMany
    {
        return $this->belongsToMany(Claim::class, 'content_module_claim')
            ->withPivot('sequence_no')
            ->withTimestamps()
            ->orderByPivot('sequence_no');
    }

    public function rules()
    {
        return $this->hasMany(ContentModuleRule::class);
    }
}
