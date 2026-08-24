<?php

namespace App\Http\Controllers;

use App\Models\LibraryFolder;
use App\Models\ReferenceAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LibraryFileController extends Controller
{
    /**
     * Uploads a file directly into the Library's folder tree (or the root, if
     * folder_id is blank) - a standalone ReferenceAttachment with no
     * attachable_type/id, distinct from files uploaded straight onto a document,
     * claim, or project. Reusable everywhere else via the existing attach-existing
     * flow once it's here.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'folder_id' => ['nullable', 'exists:library_folders,id'],
            'file' => ['required', 'file', 'max:512000'],
        ]);

        $folder = $validated['folder_id'] ?? null;
        $file = $request->file('file');
        $disk = 'documents';
        $directory = 'library-files/' . ($folder ?: 'root');
        $storedName = Str::uuid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs($directory, $storedName, $disk);

        ReferenceAttachment::create([
            'library_folder_id' => $folder,
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'disk' => $disk,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return redirect()
            ->route($folder ? 'library.folders.show' : 'library.folders.index', $folder ? [$folder] : [])
            ->with('status', 'File added to the library.');
    }

    public function destroy(Request $request, ReferenceAttachment $reference)
    {
        abort_unless($reference->isStandaloneLibraryFile(), 404);

        abort_unless(
            $request->user()->id === $reference->uploaded_by || $request->user()->can('access-admin'),
            403,
            'Only the uploader or an admin can remove this file.'
        );

        $folderId = $reference->library_folder_id;

        Storage::disk($reference->disk)->delete($reference->file_path);
        $reference->delete();

        return redirect()
            ->route($folderId ? 'library.folders.show' : 'library.folders.index', $folderId ? [$folderId] : [])
            ->with('status', 'File removed.');
    }
}
