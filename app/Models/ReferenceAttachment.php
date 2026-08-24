<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ReferenceAttachment extends Model
{
    protected $fillable = [
        'attachable_type', 'attachable_id', 'library_folder_id', 'title', 'category', 'disk', 'file_path',
        'original_filename', 'mime_type', 'file_size_bytes', 'uploaded_by',
    ];

    public function attachable()
    {
        return $this->morphTo();
    }

    /**
     * A file uploaded straight into the Library's folder tree, not (yet) attached to
     * any Document/Claim/Project - attachable_type/id are null for these.
     */
    public function libraryFolder()
    {
        return $this->belongsTo(LibraryFolder::class);
    }

    public function isStandaloneLibraryFile(): bool
    {
        return $this->attachable_type === null;
    }

    /**
     * Cross-team Reference Library: a short, human-readable label for wherever this
     * particular copy lives - a document title, a claim's match text, a project's own
     * shared library, or a Library folder path - shown on the library index so a
     * searcher knows the context each result came from.
     */
    public function attachableLabel(): string
    {
        if ($this->isStandaloneLibraryFile()) {
            if (! $this->library_folder_id) {
                return 'Library: (root)';
            }
            $names = collect($this->libraryFolder?->breadcrumbs() ?? [])->pluck('name');
            return 'Library: ' . ($names->isNotEmpty() ? $names->implode(' / ') : '(root)');
        }

        if (! $this->attachable) {
            return '(removed)';
        }

        return match ($this->attachable_type) {
            Document::class => "Document: {$this->attachable->title}",
            Project::class => "Project: {$this->attachable->name}",
            default => 'Claim: ' . \Illuminate\Support\Str::limit($this->attachable->match_text, 60),
        };
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function downloadUrl(): string
    {
        return route('reference-attachments.download', $this->id);
    }

    public function humanFileSize(): string
    {
        $bytes = $this->file_size_bytes ?? 0;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function download()
    {
        return Storage::disk($this->disk)->download($this->file_path, $this->original_filename);
    }
}
