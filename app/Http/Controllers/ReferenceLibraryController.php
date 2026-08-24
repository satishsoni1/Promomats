<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Models\ReferenceAttachment;
use App\Services\ReferenceLibraryService;
use Illuminate\Http\Request;

class ReferenceLibraryController extends Controller
{
    /**
     * Cross-team Reference Library: every reference file uploaded anywhere in the
     * system (attached to any document or claim, by any team), in one searchable
     * place - a direct alternative to the AI-suggestion flow (REQ-3.2) for a user who
     * already knows what they're looking for. Deliberately a flat list of individual
     * uploaded copies rather than a deduplicated "canonical file" index - grouping by
     * title would be fragile (two unrelated files can share a name), and each row
     * already shows exactly where it lives so a searcher can judge relevance herself.
     */
    public function index(Request $request)
    {
        $references = ReferenceAttachment::with(['attachable', 'uploader'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->q;
                $q->where(fn ($w) => $w->where('title', 'like', "%{$term}%")
                    ->orWhere('original_filename', 'like', "%{$term}%"));
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('project'), function ($q) use ($request) {
                // A project's files live in two places: uploaded directly to the project
                // itself, or attached to one of the project's documents - both count as
                // "this project's" reference material.
                $projectId = $request->integer('project');
                $documentIds = Document::where('project_id', $projectId)->pluck('id');

                $q->where(function ($w) use ($projectId, $documentIds) {
                    $w->where(fn ($x) => $x->where('attachable_type', Project::class)->where('attachable_id', $projectId));
                    if ($documentIds->isNotEmpty()) {
                        $w->orWhere(fn ($x) => $x->where('attachable_type', Document::class)->whereIn('attachable_id', $documentIds));
                    }
                });
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $categories = ReferenceAttachment::whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $projects = Project::where('status', 'active')->orderBy('name')->get(['id', 'name']);

        // If arrived from a specific document (Browse Library link), and the current
        // user owns it, every row gets a direct one-click "Attach to {document}" button
        // instead of a generic document picker.
        $targetDocument = null;
        if ($request->filled('document')) {
            $candidate = Document::find($request->integer('document'));
            if ($candidate && $candidate->owner_id === $request->user()->id) {
                $targetDocument = $candidate;
            }
        }

        // Otherwise, offer a picker of the user's own draft/in-progress documents -
        // attaching a reference requires document ownership (same rule as the
        // per-document "attach existing" action).
        $myDocuments = $targetDocument
            ? collect()
            : Document::where('owner_id', $request->user()->id)->orderByDesc('updated_at')->limit(50)->get(['id', 'title']);

        return view('library.references.index', compact('references', 'categories', 'projects', 'targetDocument', 'myDocuments'));
    }

    /**
     * Generic (non document-scoped) attach action for the library page - resolves and
     * authorizes the target document from the request body rather than the route,
     * since the library is browsed independently of any one document.
     */
    public function attach(Request $request, ReferenceAttachment $reference, ReferenceLibraryService $library)
    {
        $validated = $request->validate([
            'document_id' => ['required', 'exists:documents,id'],
        ]);

        $document = Document::findOrFail($validated['document_id']);
        abort_unless($document->owner_id === $request->user()->id, 403, 'You can only attach references to documents you own.');

        try {
            $library->attachToDocument($reference, $document, $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['reference' => 'Could not attach that reference: ' . $e->getMessage()]);
        }

        return back()->with('status', "Reference \"{$reference->title}\" attached to \"{$document->title}\".");
    }
}
