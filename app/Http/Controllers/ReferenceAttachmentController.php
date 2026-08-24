<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Document;
use App\Models\Project;
use App\Models\ReferenceAttachment;
use App\Services\ReferenceLibraryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReferenceAttachmentController extends Controller
{
    public function __construct(protected ReferenceLibraryService $library) {}

    /**
     * REQ-2.3: upload a reference file attached directly to a document (a source PDF,
     * a study, a certificate) - separate from the document's own primary version.
     */
    public function storeForDocument(Request $request, Document $document)
    {
        $validated = $this->validateUpload($request);

        $this->store($document, $validated, $request);

        return back()->with('status', 'Reference file attached.');
    }

    /**
     * Same capability, attached to a Claim instead - lets a claim carry its actual
     * substantiating document, not just a citation string.
     */
    public function storeForClaim(Request $request, Claim $claim)
    {
        $validated = $this->validateUpload($request);

        $this->store($claim, $validated, $request);

        return back()->with('status', 'Reference file attached.');
    }

    public function destroyForDocument(Document $document, ReferenceAttachment $reference)
    {
        abort_unless($reference->attachable_type === Document::class && $reference->attachable_id === $document->id, 404);
        $this->destroy($reference);
        return back()->with('status', 'Reference file removed.');
    }

    /**
     * Project-wise centralized library: uploads a file directly into a project's own
     * shared reference library, not tied to any single document - the "project wise
     * can add into library and can upload" path.
     */
    public function storeForProject(Request $request, Project $project)
    {
        $validated = $this->validateUpload($request);

        $this->store($project, $validated, $request);

        return back()->with('status', 'Reference file added to project library.');
    }

    /**
     * Project libraries are open for anyone to add to (self-serve, cross-team), but
     * removal is limited to whoever uploaded it, the project's lead/creator, or an
     * admin - the same moderation model as a shared drive, not a free-for-all delete.
     */
    public function destroyForProject(Request $request, Project $project, ReferenceAttachment $reference)
    {
        abort_unless($reference->attachable_type === Project::class && $reference->attachable_id === $project->id, 404);

        $user = $request->user();
        abort_unless(
            $user->id === $reference->uploaded_by || $user->id === $project->created_by || $user->id === $project->lead_id || $user->can('access-admin'),
            403,
            'Only the uploader, the project lead/creator, or an admin can remove this file.'
        );

        $this->destroy($reference);
        return back()->with('status', 'Reference file removed.');
    }

    public function destroyForClaim(Claim $claim, ReferenceAttachment $reference)
    {
        abort_unless($reference->attachable_type === Claim::class && $reference->attachable_id === $claim->id, 404);
        $this->destroy($reference);
        return back()->with('status', 'Reference file removed.');
    }

    public function download(ReferenceAttachment $reference)
    {
        return $reference->download();
    }

    /**
     * REQ-3.2 / cross-team library: attaches a reference that already exists elsewhere
     * in the system (an AI suggestion, or a manual pick from the Reference Library)
     * onto this document.
     */
    public function attachExisting(Request $request, Document $document)
    {
        $validated = $request->validate([
            'reference_attachment_id' => ['required', 'exists:reference_attachments,id'],
        ]);

        $source = ReferenceAttachment::findOrFail($validated['reference_attachment_id']);

        try {
            $this->library->attachToDocument($source, $document, $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['reference' => 'Could not attach that reference: ' . $e->getMessage()]);
        }

        return back()->with('status', "Reference \"{$source->title}\" attached.");
    }

    protected function validateUpload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'file' => ['required', 'file', 'max:512000'],
        ]);
    }

    protected function store($attachable, array $validated, Request $request): ReferenceAttachment
    {
        $file = $request->file('file');
        $disk = 'documents';
        $directory = 'reference-attachments/' . Str::plural(Str::snake(class_basename($attachable))) . '/' . $attachable->id;
        $storedName = Str::uuid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs($directory, $storedName, $disk);

        return $attachable->referenceAttachments()->create([
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'disk' => $disk,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);
    }

    protected function destroy(ReferenceAttachment $reference): void
    {
        Storage::disk($reference->disk)->delete($reference->file_path);
        $reference->delete();
    }
}
