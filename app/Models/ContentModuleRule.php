<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentModuleRule extends Model
{
    protected $fillable = ['content_module_id', 'rule_text'];

    public function contentModule()
    {
        return $this->belongsTo(ContentModule::class);
    }
}
