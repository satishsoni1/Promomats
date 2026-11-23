<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\LibraryFolder;
use App\Models\ReferenceAttachment;
use App\Models\User;
use Illuminate\Http\Request;

class LibraryFolderController extends Controller
{
    /**
     * Full file-system-style Reference Library: folders and subfolders any user can
     * create, browse, and upload directly into - independent of any one document,
     * claim, or project. Files placed here stay reusable everywhere else in the
     * system via the existing copy-on-attach flow (ReferenceLibraryService), so this
     * is the same physical library, just with real folder organization on top.
     */
    public function index(Request $request)
    {
        return $this->render($request, null);
    }

    public function show(Request $request, LibraryFolder $folder)
    {
        return $this->render($request, $folder);
    }

    protected function render(Request $request, ?LibraryFolder $folder)
    {
        // "All the jobs should be visible in the Library": the Jobs tab lists every
        // job this person can see - in progress (as a stakeholder) and approved.
        if (! $folder && $request->query('view') === 'jobs') {
            return $this->jobs($request);
        }

        $subfolders = LibraryFolder::withCount(['children', 'files'])
            ->with('creator')
            ->where('parent_id', $folder?->id)
            ->orderBy('name')
            ->get();

        $files = ReferenceAttachment::whereNull('attachable_type')
            ->where('library_folder_id', $folder?->id)
            ->with('uploader')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%' . $request->q . '%')
                ->orWhere('original_filename', 'like', '%' . $request->q . '%')))
            ->when($request->filled('owner_id'), fn ($q) => $q->where('uploaded_by', $request->owner_id))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderByDesc('created_at')
            // Paginated rather than ->get() - a folder is a nice small collection today,
            // but nothing stops a heavily-used one from accumulating hundreds of files
            // over time, and an unbounded list would load and render every one of them.
            ->paginate(50)
            ->withQueryString();

        $owners = User::whereIn('id', ReferenceAttachment::whereNull('attachable_type')->pluck('uploaded_by')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        $breadcrumbs = $folder?->breadcrumbs() ?? [];

        // If arrived from a specific document (Browse Library link) and the current
        // user owns it, every file gets a direct one-click "Attach" button.
        $targetDocument = null;
        if ($request->filled('document')) {
            $candidate = Document::find($request->integer('document'));
            if ($candidate && $candidate->owner_id === $request->user()->id) {
                $targetDocument = $candidate;
            }
        }

        $myDocuments = $targetDocument
            ? collect()
            : Document::where('owner_id', $request->user()->id)->orderByDesc('updated_at')->limit(50)->get(['id', 'title']);

        return view('library.folders.index', compact('folder', 'subfolders', 'files', 'owners', 'breadcrumbs', 'targetDocument', 'myDocuments'));
    }

    protected function jobs(Request $request)
    {
        $stage = $request->query('stage', 'all'); // all | progress | approved
        $base = fn () => Document::visibleTo($request->user());

        $jobs = $base()
            ->with(['owner', 'documentType', 'brand'])
            ->when($stage === 'progress', fn ($q) => $q->whereIn('status', \App\Http\Controllers\ActiveWorkflowController::ACTIVE_STATUSES))
            ->when($stage === 'approved', fn ($q) => $q->whereIn('status', Document::LIBRARY_STATUSES))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%' . $request->q . '%')
                ->orWhere('reference_no', 'like', '%' . $request->q . '%')))
            ->latest('updated_at')
            ->paginate(30)
            ->withQueryString();

        $counts = [
            'all' => $base()->count(),
            'progress' => $base()->whereIn('status', \App\Http\Controllers\ActiveWorkflowController::ACTIVE_STATUSES)->count(),
            'approved' => $base()->whereIn('status', Document::LIBRARY_STATUSES)->count(),
        ];

        return view('library.jobs', compact('jobs', 'stage', 'counts'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:library_folders,id'],
        ]);

        $folder = LibraryFolder::create([
            'name' => $validated['name'],
            'parent_id' => $validated['parent_id'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route($folder->parent_id ? 'library.folders.show' : 'library.folders.index', $folder->parent_id ? [$folder->parent_id] : [])
            ->with('status', "Folder \"{$folder->name}\" created.");
    }

    /**
     * Only an empty folder can be removed - deleting a folder that still holds
     * subfolders or files would silently orphan or cascade-delete real content, so
     * this asks the user to clear it out first instead of guessing what they meant.
     */
    public function destroy(Request $request, LibraryFolder $folder)
    {
        abort_unless(
            $request->user()->id === $folder->created_by || $request->user()->can('access-admin'),
            403,
            'Only the folder\'s creator or an admin can remove it.'
        );

        if (! $folder->isEmpty()) {
            return back()->withErrors(['folder' => 'This folder still has files or subfolders in it - move or remove those first.']);
        }

        $parentId = $folder->parent_id;
        $folder->delete();

        return redirect()
            ->route($parentId ? 'library.folders.show' : 'library.folders.index', $parentId ? [$parentId] : [])
            ->with('status', 'Folder removed.');
    }
}
