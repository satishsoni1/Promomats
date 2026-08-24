<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DocumentVersion extends Model
{
    protected $fillable = [
        'document_id', 'version_no', 'version_label', 'original_filename',
        'disk', 'file_path', 'mime_type', 'file_size_bytes', 'checksum_sha256',
        'change_notes', 'uploaded_by', 'is_current',
    ];

    protected $casts = ['is_current' => 'boolean'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * REQ-2.4: embedded hyperlinks extracted from this version's PDF Link annotations.
     */
    public function pdfLinks()
    {
        return $this->hasMany(PdfLink::class)->orderBy('page_number');
    }

    /**
     * Real, in-place content edits (add/redact) baked into this version's PDF bytes
     * via the browser-side PDF editor - see App\Models\PdfEdit.
     */
    public function pdfEdits()
    {
        return $this->hasMany(PdfEdit::class)->with('actor')->latest();
    }

    public function downloadUrl(): string
    {
        return route('documents.versions.download', $this->id);
    }

    public function viewUrl(): string
    {
        return route('documents.versions.view', $this->id);
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf'
            || strtolower(pathinfo($this->original_filename, PATHINFO_EXTENSION)) === 'pdf';
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime_type, 'video/')
            || in_array(strtolower(pathinfo($this->original_filename, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'm4v']);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/')
            || in_array(strtolower(pathinfo($this->original_filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
    }

    public function humanFileSize(): string
    {
        $bytes = $this->file_size_bytes ?? 0;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
