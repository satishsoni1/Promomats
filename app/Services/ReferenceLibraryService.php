<?php

namespace App\Services;

use App\Models\Document;
use App\Models\ReferenceAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cross-team Reference Library: lets any reference file already uploaded anywhere in
 * the system (attached to some other team's document, or to a claim) be reused on a
 * new document. Physically copies the file into a new row/path of its own rather than
 * pointing two rows at the same file_path - ReferenceAttachmentController::destroy()
 * deletes the underlying file unconditionally, so sharing a path between rows would
 * let removing one reference silently break the other.
 */
class ReferenceLibraryService
{
    public function attachToDocument(ReferenceAttachment $source, Document $target, User $actor): ReferenceAttachment
    {
        $sourceDisk = Storage::disk($source->disk);
        if (! $sourceDisk->exists($source->file_path)) {
            throw new \RuntimeException('The source file could not be found.');
        }

        $directory = 'reference-attachments/documents/' . $target->id;
        $storedName = Str::uuid() . '_' . $source->original_filename;
        $stream = $sourceDisk->readStream($source->file_path);
        Storage::disk('documents')->put($directory . '/' . $storedName, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $target->referenceAttachments()->create([
            'title' => $source->title,
            'category' => $source->category,
            'disk' => 'documents',
            'file_path' => $directory . '/' . $storedName,
            'original_filename' => $source->original_filename,
            'mime_type' => $source->mime_type,
            'file_size_bytes' => $source->file_size_bytes,
            'uploaded_by' => $actor->id,
        ]);
    }
}
