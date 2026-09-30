<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentComment extends Model
{
    protected $fillable = [
        'document_id', 'document_version_id', 'parent_id', 'user_id', 'body',
        'page_number', 'x_position', 'y_position', 'selected_text', 'replacement_text', 'highlight_rects',
        'timestamp_seconds', 'resolved_at', 'resolved_by', 'edited_at',
    ];

    protected $casts = [
        'x_position' => 'float',
        'y_position' => 'float',
        'timestamp_seconds' => 'float',
        'highlight_rects' => 'array',
        'resolved_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
