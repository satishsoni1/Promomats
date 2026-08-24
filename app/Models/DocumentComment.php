<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentComment extends Model
{
    protected $fillable = ['document_id', 'parent_id', 'user_id', 'body', 'page_number', 'x_position', 'y_position', 'timestamp_seconds'];

    protected $casts = ['x_position' => 'float', 'y_position' => 'float', 'timestamp_seconds' => 'float'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent()
    {
        return $this->belongsTo(DocumentComment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(DocumentComment::class, 'parent_id')->with('author')->oldest();
    }
}
