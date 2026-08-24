<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentVersionService
{
    /**
     * Store a new version of a document. Works for any file type; size is bounded only
     * by php.ini (upload_max_filesize / post_max_size) and the storage disk configured
     * for large files (see config/filesystems.php "documents" disk - point this at S3
     * or a large local volume for big files, e.g. video/print-ready artwork).
     */
    public function storeNewVersion(
        Document $document,
        UploadedFile $file,
        User $uploader,
        ?string $changeNotes = null,
        ?string $versionLabel = null
    ): DocumentVersion {
        return DB::transaction(function () use ($document, $file, $uploader, $changeNotes, $versionLabel) {
            $nextVersionNo = ($document->versions()->max('version_no') ?? 0) + 1;

            $disk = 'documents'; // configured in config/filesystems.php
            $directory = "documents/{$document->id}/versions";
            $storedName = $nextVersionNo . '_' . Str::uuid() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs($directory, $storedName, $disk);

            $checksum = hash_file('sha256', $file->getRealPath());

            // clear previous "current" flag
            $document->versions()->update(['is_current' => false]);

            $version = DocumentVersion::create([
                'document_id' => $document->id,
                'version_no' => $nextVersionNo,
                'version_label' => $versionLabel,
                'original_filename' => $file->getClientOriginalName(),
                'disk' => $disk,
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size_bytes' => $file->getSize(),
                'checksum_sha256' => $checksum,
                'change_notes' => $changeNotes,
                'uploaded_by' => $uploader->id,
                'is_current' => true,
            ]);

            $document->update(['current_version_id' => $version->id]);

            return $version;
        });
    }

    public function download(DocumentVersion $version)
    {
        return Storage::disk($version->disk)->download($version->file_path, $version->original_filename);
    }
}
