<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfEdit extends Model
{
    protected $fillable = [
        'document_version_id', 'actor_id', 'edit_type', 'page_number', 'x', 'y', 'width', 'height', 'content',
    ];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
        'height' => 'float',
    ];

    public function documentVersion()
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function typeLabel(): string
    {
        return match ($this->edit_type) {
            'add_text' => 'Added text',
            'redact' => 'Redacted content',
            default => ucfirst($this->edit_type),
        };
    }
}
