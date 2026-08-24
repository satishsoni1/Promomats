<?php

namespace App\Http\Controllers;

use App\Models\AiInsight;
use App\Models\Brand;
use App\Models\Claim;
use App\Models\ContentModule;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\DocumentActionNotification;
use App\Services\AiService;
use App\Services\Audit\AuditLogger;
use App\Services\DocumentVersionService;
use App\Services\Workflow\WorkflowResolver;
use App\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentVersionService $versionService,
        protected WorkflowEngine $engine,
        protected AiService $ai,
        protected AuditLogger $audit,
        protected WorkflowResolver $workflowResolver,
    ) {}

    public function index(Request $request)
    {
        $documents = Document::query()
            ->with(['owner', 'currentVersion', 'workflowTemplate'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('mine'), fn ($q) => $q->where('owner_id', $request->user()->id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('title', 'like', "%{$request->search}%")
                        ->orWhere('reference_no', 'like', "%{$request->search}%");
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // Sidebar filter facets: live counts per status/category, PromoMats-library style.
        $statusCounts = Document::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $categoryCounts = Document::whereNotNull('category')->selectRaw('category, count(*) as c')->groupBy('category')->pluck('c', 'category');
        $view = $request->get('view', 'table') === 'grid' ? 'grid' : 'table';

        return view('documents.index', compact('documents', 'statusCounts', 'categoryCounts', 'view'));
    }

    public function create()
    {
        $templates = WorkflowTemplate::where('is_active', true)->get();
        $projects = Project::where('status', 'active')->orderBy('name')->get(['id', 'name', 'target_audience']);
        $brands = Brand::active()->orderBy('name')->get();
        $documentTypes = DocumentType::active()->orderBy('name')->get();
        // Active workflow_rules, shaped for the create form's Alpine component to
        // do the same brand+type -> workflow lookup WorkflowResolver does
        // server-side, without a round trip - see resources/views/documents/create.blade.php.
        $workflowRules = \App\Models\WorkflowRule::where('status', 'active')
            ->orderBy('priority')
            ->get(['workflow_template_id', 'brand_id', 'document_type_id', 'department', 'priority']);

        return view('documents.create', compact('templates', 'projects', 'brands', 'documentTypes', 'workflowRules'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:120'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'document_type_id' => ['nullable', 'exists:document_types,id'],
            'products' => ['nullable', 'string', 'max:500'],
            'countries' => ['nullable', 'string', 'max:500'],
            'target_audience' => ['nullable', 'string', 'in:' . implode(',', array_keys(\App\Support\TargetAudience::LABELS))],
            // Not required server-side: left blank, it's resolved automatically
            // from brand + document type via WorkflowResolver below (spec REQ-26)
            // rather than forcing a manual pick when a rule already covers it.
            'workflow_template_id' => ['nullable', 'exists:workflow_templates,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'start_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'aging_warning_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            // Any file type accepted; adjust `max` (in KB) to your infra's real ceiling.
            // For very large files (100MB+), pair this with resumable/chunked upload on the frontend.
            'file' => ['required', 'file', 'max:512000'], // 500MB via standard form upload
        ]);

        $documentType = $validated['document_type_id'] ? DocumentType::find($validated['document_type_id']) : null;

        if ($documentType) {
            $extension = $request->file('file')->getClientOriginalExtension();
            if (! $documentType->acceptsExtension($extension)) {
                return back()->withErrors(['file' => "\"{$documentType->name}\" only accepts: " . implode(', ', $documentType->allowed_extensions)])->withInput();
            }
            if (! $documentType->acceptsFileSize($request->file('file')->getSize())) {
                return back()->withErrors(['file' => "\"{$documentType->name}\" allows files up to {$documentType->max_file_size_kb} KB."])->withInput();
            }
        }

        $workflowTemplateId = $validated['workflow_template_id'] ?? null;

        if (! $workflowTemplateId) {
            $brand = $validated['brand_id'] ? Brand::find($validated['brand_id']) : null;
            $resolved = $this->workflowResolver->resolve($brand, $documentType, $request->user()->department);
            $workflowTemplateId = $resolved?->id;
        }

        if (! $workflowTemplateId) {
            return back()->withErrors(['workflow_template_id' => 'No workflow could be determined automatically for this Brand/Document Type - please select one.'])->withInput();
        }

        $countries = $this->splitTags($validated['countries'] ?? null);

        $document = Document::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? null,
            'brand_id' => $validated['brand_id'] ?? null,
            'document_type_id' => $validated['document_type_id'] ?? null,
            'products' => $this->splitTags($validated['products'] ?? null),
            'countries' => $countries,
            'target_audience' => $validated['target_audience'] ?? null,
            'reference_no' => $this->generateReferenceNo($validated['category'] ?? null, $countries),
            'owner_id' => $request->user()->id,
            'workflow_template_id' => $workflowTemplateId,
            'project_id' => $validated['project_id'] ?? null,
            'status' => 'draft',
            'start_date' => $validated['start_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'aging_warning_days' => $validated['aging_warning_days'] ?? 30,
        ]);

        $this->audit->record(action: 'DOCUMENT_CREATED', document: $document, actor: $request->user(), newStatus: 'draft');

        $version = $this->versionService->storeNewVersion(
            document: $document,
            file: $request->file('file'),
            uploader: $request->user(),
            changeNotes: 'Initial upload',
        );

        $this->audit->record(action: 'VERSION_CREATED', document: $document, version: $version, actor: $request->user());

        return redirect()->route('documents.show', $document)->with('status', 'Document uploaded. Submit it for review when ready.');
    }

    public function show(Document $document)
    {
        $this->authorize('view', $document);

        $this->audit->record(action: 'VIEWED', document: $document, actor: request()->user());

        $document->load([
            'versions.uploader',
            'approvalActions.actor',
            'approvalActions.stage',
            'activeWorkflowInstance.currentStage.approvers',
            'activeWorkflowInstance.pendingAssignees.user',
            'activeWorkflowInstance.pendingAssignees.stage',
            'workflowTemplate.stages',
            'comments',
            'pdfAnnotations',
            'videoAnnotations',
            'claims.references',
            'referenceAttachments.uploader',
            'retrievalRequests.requestedBy',
            'currentVersion.pdfLinks.matchedReference',
            'currentVersion.pdfEdits.actor',
            'versions.pdfEdits.actor',
            'claimReferenceMappings',
            'project.cycles',
            'cycle',
            'watchers',
            'brand',
            'documentType',
            'distributionRecords',
        ]);

        $availableClaims = Claim::where('status', 'approved')->orderBy('match_text')->get();
        $availableModules = ContentModule::where('status', 'approved')->orderBy('name')->get();
        $availableProjects = Project::where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $observableUsers = User::where('is_active', true)
            ->whereNotIn('id', $document->watchers->pluck('id')->push($document->owner_id))
            ->orderBy('name')
            ->get(['id', 'name']);

        // Lightweight "suggested claims" - approved claims not yet in this document whose
        // product matches, or whose claim text already appears in the title/description.
        // A simple substring heuristic, not real NLP matching - PromoMats' auto-linking is
        // a much deeper text-scanning feature; this gets the same "did you mean to use this
        // claim?" prompt without that investment.
        $haystack = strtolower($document->title . ' ' . $document->description);
        $linkedIds = $document->claims->pluck('id');
        $suggestedClaims = $availableClaims
            ->reject(fn ($c) => $linkedIds->contains($c->id))
            ->filter(function ($c) use ($haystack, $document) {
                if ($c->product && collect($document->products)->contains(fn ($p) => str_contains(strtolower($p), strtolower($c->product)))) {
                    return true;
                }
                return str_contains($haystack, strtolower($c->match_text)) || str_contains(strtolower($c->match_text), strtolower($document->title));
            })
            ->take(5);

        // Page-anchored comment pins, grouped by page number, in the shape the PDF
        // viewer's Alpine component expects: { "1": [ {id, x_position, ...} ], ... }
        $pdfAnnotationsByPage = $document->pdfAnnotations
            ->groupBy('page_number')
            ->mapWithKeys(fn ($group, $page) => [
                (string) $page => $group->map(fn ($c) => [
                    'id' => $c->id,
                    'x_position' => $c->x_position,
                    'y_position' => $c->y_position,
                    'body' => $c->body,
                    'created_at' => $c->created_at->diffForHumans(),
                    'author' => ['name' => $c->author?->name],
                    'replies' => $c->replies->map(fn ($r) => [
                        'id' => $r->id,
                        'body' => $r->body,
                        'created_at' => $r->created_at->diffForHumans(),
                        'author' => ['name' => $r->author?->name],
                    ]),
                ]),
            ]);

        // Time-anchored video pins (REQ-2.1), in the flat shape the video player's
        // Alpine component expects - unlike PDF pins there's no "page" to group by, just
        // chronological order along the timeline.
        $videoAnnotations = $document->videoAnnotations->map(fn ($c) => [
            'id' => $c->id,
            'timestamp_seconds' => $c->timestamp_seconds,
            'body' => $c->body,
            'created_at' => $c->created_at->diffForHumans(),
            'author' => ['name' => $c->author?->name],
            'replies' => $c->replies->map(fn ($r) => [
                'id' => $r->id,
                'body' => $r->body,
                'created_at' => $r->created_at->diffForHumans(),
                'author' => ['name' => $r->author?->name],
            ]),
        ])->values();

        $latestComplianceInsight = AiInsight::where('document_id', $document->id)->where('type', 'compliance_check')->latest()->first();
        $latestRevisionInsight = AiInsight::where('document_id', $document->id)->where('type', 'revision_suggestion')->latest()->first();

        // REQ-4.3: most recent cold storage retrieval request, if any - retrievalRequests()
        // is already ordered latest() so first() is the newest.
        $latestRetrievalRequest = $document->retrievalRequests->first();

        // REQ-3.3: claim<->reference mapping pins, grouped by page in the same shape
        // pdfAnnotationsByPage uses, for the PDF viewer's Alpine component.
        $claimReferenceMappingsByPage = $document->claimReferenceMappings
            ->groupBy('page_number')
            ->mapWithKeys(fn ($group, $page) => [
                (string) $page => $group->map(fn ($m) => [
                    'id' => $m->id,
                    'x_position' => $m->x_position,
                    'y_position' => $m->y_position,
                    'selected_text' => $m->selected_text,
                    'claim' => $m->claim ? ['id' => $m->claim->id, 'match_text' => $m->claim->match_text] : null,
                    'reference' => $m->referenceAttachment ? ['id' => $m->referenceAttachment->id, 'title' => $m->referenceAttachment->title, 'download_url' => $m->referenceAttachment->downloadUrl()] : null,
                    'creator' => ['name' => $m->creator?->name],
                ]),
            ]);

        return view('documents.show', compact(
            'document', 'availableClaims', 'availableModules', 'suggestedClaims', 'pdfAnnotationsByPage',
            'videoAnnotations', 'latestComplianceInsight', 'latestRevisionInsight', 'latestRetrievalRequest',
            'claimReferenceMappingsByPage', 'availableProjects', 'observableUsers'
        ))->with('aiAvailable', $this->ai->isAvailable());
    }

    /**
     * First submission into the workflow, or re-submission of a fresh version
     * after an AwC/NA revision loop.
     */
    public function submit(Request $request, Document $document)
    {
        // Uploading a revised file is open to the owner or Agency (uploadVersion),
        // but deciding it's ready to go into (or back into) the approval flow is
        // owner/admin only.
        $this->authorize('submitForReview', $document);

        $instance = $document->activeWorkflowInstance;
        $awaitingRevision = $document->status === 'approved_with_changes_pending';

        // A "running" instance is expected here when we're re-submitting after an
        // AwC/NA revision loop (the instance never stopped running, it's just parked
        // waiting on a new version) - only block a genuinely duplicate submission.
        if ($instance && $instance->status === 'running' && ! $awaitingRevision) {
            abort(422, 'This document already has an active workflow in progress.');
        }

        if ($awaitingRevision) {
            $document->assertNotOnLegalHold();

            // The file is optional here: Agency may already have uploaded the
            // revised version separately via uploadNewVersion() - the owner just
            // needs to confirm it's ready and send it back into the flow. Only
            // require a fresh upload if nothing newer than what was last reviewed
            // exists yet.
            $request->validate(['file' => ['nullable', 'file', 'max:512000']]);

            if ($request->hasFile('file')) {
                $newVersion = $this->versionService->storeNewVersion(
                    document: $document,
                    file: $request->file('file'),
                    uploader: $request->user(),
                    changeNotes: $request->input('change_notes', 'Revision after Approved with Changes / Not Approved'),
                );
            } elseif ($document->current_version_id && $document->current_version_id !== $instance->document_version_id) {
                $newVersion = $document->currentVersion;
            } else {
                return back()->withErrors(['file' => 'Upload a revised file before resubmitting - no new version has been uploaded since the last review.']);
            }

            $this->engine->resumeAfterRevision($instance, $newVersion);
        } else {
            $this->engine->start($document, $document->currentVersion, $request->user());
        }

        return back()->with('status', 'Document submitted into the approval workflow.');
    }

    public function uploadNewVersion(Request $request, Document $document)
    {
        $this->authorize('uploadVersion', $document);
        $document->assertNotOnLegalHold();

        $request->validate([
            'file' => ['required', 'file', 'max:512000'],
            'change_notes' => ['nullable', 'string'],
        ]);

        $version = $this->versionService->storeNewVersion(
            document: $document,
            file: $request->file('file'),
            uploader: $request->user(),
            changeNotes: $request->change_notes,
        );

        $this->audit->record(action: 'VERSION_CREATED', document: $document, version: $version, actor: $request->user(), description: $request->change_notes);

        $this->notifyStakeholders($document, 'version_uploaded', $request->user());

        return back()->with('status', 'New version uploaded.');
    }

    public function downloadVersion(\App\Models\DocumentVersion $version)
    {
        $this->authorize('view', $version->document);

        $this->audit->record(action: 'DOWNLOADED', document: $version->document, version: $version, actor: request()->user());

        return app(DocumentVersionService::class)->download($version);
    }

    /**
     * Stream a version inline (not as an attachment) so the browser/pdf.js can render
     * it directly - backs the in-page PDF/video/image viewers.
     *
     * Security: only PDF/video/image are ever served inline, and only with a
     * Content-Type from that safe allowlist - never the client-supplied mime_type
     * captured at upload time. Any other file type (in particular HTML, SVG, or a
     * PDF/JPEG-renamed-with-a-lying-extension) is forced to application/octet-stream,
     * which browsers download rather than execute. Without this, a file uploaded as
     * e.g. "claim.pdf" with a spoofed text/html content type would be rendered - and
     * any script inside it *executed* - in this app's own origin: stored XSS via
     * file upload.
     */
    public function viewVersion(\App\Models\DocumentVersion $version)
    {
        $contentType = match (true) {
            $version->isPdf() => 'application/pdf',
            $version->isImage() => $this->safeImageMimeType($version),
            $version->isVideo() => $this->safeVideoMimeType($version),
            default => 'application/octet-stream',
        };

        return \Illuminate\Support\Facades\Storage::disk($version->disk)->response(
            $version->file_path,
            $version->original_filename,
            ['Content-Type' => $contentType]
        );
    }

    protected function safeImageMimeType(\App\Models\DocumentVersion $version): string
    {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        // Deliberately excludes image/svg+xml - an SVG can carry a <script> tag, so it
        // doesn't belong on a "safe to render inline" allowlist even though it's an image.
        return in_array($version->mime_type, $allowed, true) ? $version->mime_type : 'application/octet-stream';
    }

    protected function safeVideoMimeType(\App\Models\DocumentVersion $version): string
    {
        $allowed = ['video/mp4', 'video/webm', 'video/quicktime'];
        return in_array($version->mime_type, $allowed, true) ? $version->mime_type : 'application/octet-stream';
    }

    /**
     * Manual lifecycle transitions that sit outside the approval workflow itself -
     * marking a fully-approved document "Approved for Production" vs. "Approved for
     * Distribution", or retiring one as superseded/obsolete once it's no longer current.
     */
    public function updateStatus(Request $request, Document $document)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:approved_for_production,superseded,obsolete,archived'],
        ]);

        $this->authorize('update', $document);
        $document->assertNotOnLegalHold();

        $oldStatus = $document->status;
        $document->update(['status' => $validated['status']]);

        $this->audit->record(
            action: $validated['status'] === 'archived' ? 'ARCHIVED' : 'DOCUMENT_UPDATED',
            document: $document,
            actor: $request->user(),
            oldStatus: $oldStatus,
            newStatus: $validated['status'],
        );

        $this->notifyStakeholders($document, 'lifecycle_changed', $request->user());

        return back()->with('status', "Document marked \"{$document->statusLabel()}\".");
    }

    /**
     * Quick project (re)assignment for an already-created document - project_id is
     * optional at creation, so this covers assigning one later or moving a document
     * between projects.
     */
    public function updateProject(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $validated = $request->validate([
            'project_id' => ['nullable', 'exists:projects,id'],
        ]);

        $newProjectId = $validated['project_id'] ?? null;

        $document->update([
            'project_id' => $newProjectId,
            // A cycle always belongs to one specific project - changing (or clearing)
            // the document's project makes any previously-assigned cycle invalid, so
            // it's cleared here rather than left pointing at a cycle under the wrong
            // (or no) project.
            'cycle_id' => $newProjectId === $document->project_id ? $document->cycle_id : null,
        ]);

        return back()->with('status', $newProjectId ? 'Document assigned to project.' : 'Document removed from project.');
    }

    /**
     * Quick cycle (re)assignment for an already-created document, scoped to whichever
     * project the document is currently in - mirrors updateProject() above.
     */
    public function updateCycle(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        abort_unless($document->project_id, 422, 'Assign this document to a project before assigning a cycle.');

        $validated = $request->validate([
            'cycle_id' => ['nullable', 'exists:project_cycles,id'],
        ]);

        if ($validated['cycle_id'] ?? null) {
            $cycle = \App\Models\ProjectCycle::findOrFail($validated['cycle_id']);
            abort_unless($cycle->project_id === $document->project_id, 422, 'That cycle belongs to a different project.');
        }

        $document->update(['cycle_id' => $validated['cycle_id'] ?? null]);

        return back()->with('status', $validated['cycle_id'] ? 'Document assigned to cycle.' : 'Document removed from cycle.');
    }

    /**
     * The Document History Report - a single, complete, human-readable record of a
     * document's full lifecycle (versions, every approval decision with its
     * electronic signature, comments, and in-place content edits) formatted for
     * regulatory inspection or submission. Rendered as a plain, print-optimized
     * page rather than a server-generated PDF: the browser's native "Print to
     * PDF" already produces an identical, portable, paginated PDF with zero added
     * dependency - a standard pattern for this kind of on-demand compliance export.
     */
    public function historyReport(Document $document)
    {
        $document->load([
            'owner', 'project', 'cycle', 'workflowTemplate',
            'versions.uploader', 'versions.pdfEdits.actor',
            'approvalActions.actor', 'approvalActions.stage',
            'comments.author', 'comments.replies.author',
            'pdfAnnotations.author',
            'claims.references',
            'workflowInstances.currentStage',
            'legalHoldEvents.actor',
        ]);

        // Chronological, all-sources audit trail: version uploads, approval
        // decisions/signatures, and content edits interleaved into a single
        // timeline by timestamp - this is the section an auditor actually wants,
        // rather than three separate tables they'd have to cross-reference by hand.
        $timeline = collect()
            ->concat($document->versions->map(fn ($v) => [
                'at' => $v->created_at,
                'type' => 'version',
                'summary' => "Version {$v->version_no} uploaded ({$v->original_filename})",
                'actor' => $v->uploader?->name,
                'detail' => $v->change_notes,
            ]))
            ->concat($document->approvalActions->map(fn ($a) => [
                'at' => $a->acted_at,
                'type' => 'signature',
                'summary' => $a->decisionLabel() . ' at ' . ($a->stage?->name ?? 'unknown stage'),
                'actor' => $a->signed_name ?? $a->actor?->name,
                'detail' => $a->comments,
                'ip_address' => $a->ip_address,
            ]))
            ->concat(
                $document->versions->flatMap->pdfEdits->map(fn ($e) => [
                    'at' => $e->created_at,
                    'type' => 'edit',
                    'summary' => $e->typeLabel() . " (page {$e->page_number})",
                    'actor' => $e->actor?->name,
                    'detail' => $e->content,
                ])
            )
            ->concat($document->comments->map(fn ($c) => [
                'at' => $c->created_at,
                'type' => 'comment',
                'summary' => 'Comment added',
                'actor' => $c->author?->name,
                'detail' => $c->body,
            ]))
            ->concat($document->legalHoldEvents->map(fn ($h) => [
                'at' => $h->created_at,
                'type' => 'legal_hold',
                'summary' => $h->action === 'placed' ? 'Legal hold placed' : 'Legal hold released',
                'actor' => $h->actor?->name,
                'detail' => $h->reason,
            ]))
            ->filter(fn ($row) => $row['at'] !== null)
            ->sortBy('at')
            ->values();

        return view('documents.history-report', compact('document', 'timeline'));
    }

    /**
     * Freeze a document for litigation/regulatory inquiry - admin-only, since it's a
     * legal/compliance decision, not a document-ownership one. Writes both the
     * "current state" columns on documents (fast to check on every guarded action)
     * and a permanent DocumentLegalHold row (so releasing a hold never erases that it
     * happened).
     */
    public function placeLegalHold(Request $request, Document $document)
    {
        $this->authorize('manageLegalHold', $document);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $document->update([
            'legal_hold' => true,
            'legal_hold_reason' => $validated['reason'],
            'legal_hold_set_by' => $request->user()->id,
            'legal_hold_set_at' => now(),
        ]);

        $document->legalHoldEvents()->create([
            'action' => 'placed',
            'reason' => $validated['reason'],
            'actor_id' => $request->user()->id,
        ]);

        $this->audit->record(action: 'LEGAL_HOLD_PLACED', document: $document, actor: $request->user(), description: $validated['reason']);

        $this->notifyStakeholders($document, 'lifecycle_changed', $request->user());

        return back()->with('status', 'Legal hold placed. This document is now frozen from edits and archiving.');
    }

    public function releaseLegalHold(Request $request, Document $document)
    {
        $this->authorize('manageLegalHold', $document);

        $document->update([
            'legal_hold' => false,
            'legal_hold_reason' => null,
            'legal_hold_set_by' => null,
            'legal_hold_set_at' => null,
        ]);

        $document->legalHoldEvents()->create([
            'action' => 'released',
            'reason' => $request->input('reason'),
            'actor_id' => $request->user()->id,
        ]);

        $this->audit->record(action: 'LEGAL_HOLD_RELEASED', document: $document, actor: $request->user(), description: $request->input('reason'));

        $this->notifyStakeholders($document, 'lifecycle_changed', $request->user());

        return back()->with('status', 'Legal hold released.');
    }

    /**
     * Notify the document owner and any watchers about an activity on the document -
     * skips the person who just performed the action so they don't get notified about
     * their own click. Used for activities that sit outside the approval workflow's own
     * notify() calls (WorkflowEngine handles stage_assigned/action_taken/workflow_completed).
     */
    protected function notifyStakeholders(Document $document, string $event, User $actor): void
    {
        $recipientIds = collect([$document->owner_id])
            ->merge($document->watchers()->pluck('users.id'))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $actor->id)
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $recipientIds)->where('is_active', true)->get();

        foreach ($users as $user) {
            $user->notify(new DocumentActionNotification(document: $document, event: $event, actor: $actor));
        }
    }

    /**
     * Parse a comma-separated free-text tag field ("US, EU, Global") into a clean array.
     */
    protected function splitTags(?string $raw): ?array
    {
        if (blank($raw)) {
            return null;
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return $tags ?: null;
    }

    /**
     * Country/category-coded reference number, e.g. US-VS-202608-AB12CD, echoing
     * PromoMats' document numbering scheme (country-content_type-sequence).
     */
    protected function generateReferenceNo(?string $category, ?array $countries): string
    {
        $countryCode = $countries[0] ?? 'GL';
        $countryCode = strtoupper(Str::limit(preg_replace('/[^A-Za-z]/', '', $countryCode), 2, ''));
        $countryCode = $countryCode ?: 'GL';

        $categoryCode = $category
            ? strtoupper(Str::limit(preg_replace('/[^A-Za-z]/', '', $category), 2, ''))
            : 'VS';
        $categoryCode = $categoryCode ?: 'VS';

        return "{$countryCode}-{$categoryCode}-" . now()->format('Ym') . '-' . strtoupper(Str::random(6));
    }
}
