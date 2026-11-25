<?php

namespace App\Http\Controllers;

use App\Models\AiInsight;
use App\Models\Brand;
use App\Models\Claim;
use App\Models\ContentModule;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTransition;
use App\Notifications\DocumentActionNotification;
use App\Services\AiService;
use App\Services\Audit\AuditLogger;
use App\Services\DocumentVersionService;
use App\Services\Workflow\WorkflowResolver;
use App\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            ->visibleTo($request->user())
            ->with(['owner', 'currentVersion', 'workflowTemplate', 'documentType'])
            ->when($request->filled('team'), fn ($q) => $q->where('team', $request->team))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand_id', $request->brand))
            ->when($request->filled('sku'), fn ($q) => $q->where('sku_id', $request->sku))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->channel))
            ->when($request->filled('type'), fn ($q) => $q->where('document_type_id', $request->type))
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
        $statusCounts = Document::visibleTo($request->user())->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $categoryCounts = Document::visibleTo($request->user())->whereNotNull('category')->selectRaw('category, count(*) as c')->groupBy('category')->pluck('c', 'category');
        $view = $request->get('view', 'table') === 'grid' ? 'grid' : 'table';

        // Searchable Brand / SKU filters; SKUs narrow to the chosen brand.
        $filterBrands = Brand::orderBy('name')->get(['id', 'name']);
        $filterSkus = \App\Models\Sku::when($request->filled('brand'), fn ($q) => $q->where('brand_id', $request->brand))
            ->orderBy('name')->get(['id', 'name']);

        return view('documents.index', compact('documents', 'statusCounts', 'categoryCounts', 'view', 'filterBrands', 'filterSkus'));
    }

    public function create()
    {
        $this->authorize('create', Document::class);

        $templates = WorkflowTemplate::shared()
            ->where('is_active', true)
            ->with(['stages.approvers.role', 'stages.approvers.user'])
            ->get();
        $projects = Project::where('status', 'active')->orderBy('name')->get(['id', 'name', 'target_audience']);
        $brands = Brand::active()->orderBy('name')->get();
        $documentTypes = DocumentType::active()->orderBy('name')->get();
        // MOM 24 Nov: SKU (filtered by brand), Country and Category are dropdowns.
        $skus = \App\Models\Sku::active()->orderBy('name')->get(['id', 'brand_id', 'name', 'code']);
        $countries = \App\Models\Country::active()->orderBy('name')->get(['id', 'name', 'code']);
        $categories = Document::CATEGORIES;
        $chairpersonDefault = User::defaultChairpersonApprover();
        // Chairperson by brand division (Zeal -> Rathna, Zandra -> Jalba): every
        // division's approver is offered, and the brand's one is pre-ticked.
        $divisionChairs = User::divisionChairpersons();
        $chairpersons = collect($divisionChairs)->push($chairpersonDefault)->filter()
            ->reject(fn ($u) => $u->id === auth()->id())->unique('id')->values();
        $chairpersonByBrand = $brands->mapWithKeys(fn ($b) => [(string) $b->id => ($divisionChairs[$b->division] ?? $chairpersonDefault)?->id])
            ->reject(fn ($id) => $id === null || $id === auth()->id())->all();
        $agencies = \App\Models\ContentAgency::active()->orderBy('name')
            ->with(['users' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->get();

        // For templates whose admin has enabled owner customization: the per-stage
        // candidate people the upload form lets the owner narrow down to a named
        // approver. Shaped for the create form's Alpine component - see the
        // "Approvers for this workflow" section in documents/create.blade.php.
        $candidateUserIds = $templates->flatMap->stages->flatMap->approvers
            ->flatMap(fn ($a) => $a->resolveUserIds())->unique();
        $userNames = User::whereIn('id', $candidateUserIds)->pluck('name', 'id');
        // Brand Managers are never handed approval tasks, and the person creating the
        // job will own it - makers can't be checkers - so neither is offered.
        $bmOnlyIds = User::with('roles')->whereIn('id', $candidateUserIds)->get()
            ->filter(fn ($u) => $u->isBrandManagerOnly())->pluck('id')
            ->push(auth()->id());
        $defaultDueHours = config('promomats.due_dates.default_stage_hours', 48);

        $templateApproverOptions = $templates
            ->where('owner_can_customize_workflow', true)
            ->mapWithKeys(fn ($t) => [(string) $t->id => $t->stages->map(fn ($s) => [
                'stage_id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'sequence_no' => $s->sequence_no,
                'parallel_group' => $s->parallel_group,
                'approval_mode' => $s->approval_mode,
                'is_final' => (bool) $s->is_final_distribution_stage,
                'is_draft' => $s->isDraft(),
                'is_chairperson' => $s->isChairperson(),
                'due_hours' => $s->sla_hours ?? $defaultDueHours,
                'candidates' => $s->candidateUserIds()
                    ->when($s->isChairperson(), fn ($ids) => $chairpersons->pluck('id')->merge($ids)->unique())
                    ->reject(fn ($uid) => $bmOnlyIds->contains($uid))
                    ->map(fn ($uid) => ['id' => $uid, 'name' => $userNames[$uid] ?? $chairpersons->firstWhere('id', $uid)?->name ?? "User #{$uid}"])
                    ->values(),
                // Pre-ticked: the only candidate, or the Chairperson's default approver
                // (the create form swaps it for the selected brand's division approver).
                'default_ids' => $s->isChairperson() && $chairpersonDefault && $chairpersonDefault->id !== auth()->id()
                    ? [$chairpersonDefault->id]
                    : [],
            ])->values()]);

        // For the "add a stage" picker in the structural editor: every active user
        // and every role, so a brand-new stage can be assigned to a role (whoever
        // holds it at run time) or to named people.
        $allUsers = User::with('roles')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->reject(fn ($u) => $u->isBrandManagerOnly() || $u->id === auth()->id())
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values();
        $allRoles = Role::orderBy('name')->get(['id', 'name']);
        // Active workflow_rules, shaped for the create form's Alpine component to
        // do the same brand+type -> workflow lookup WorkflowResolver does
        // server-side, without a round trip - see resources/views/documents/create.blade.php.
        $workflowRules = \App\Models\WorkflowRule::where('status', 'active')
            ->orderBy('priority')
            ->get(['workflow_template_id', 'brand_id', 'document_type_id', 'department', 'priority']);

        // Pharma / Scientific Publications: the form starts on the user's own team.
        $teams = config('promomats.teams');
        $defaultTeam = \App\Support\Teams::forUser(auth()->user());

        // Pharma: R&D / Legal / Regulatory Affairs must each be selected - which
        // people and roles count for which group, for the form's live checklist.
        $requiredStakeholders = collect(array_keys($teams))->mapWithKeys(fn ($team) => [$team => [
            'groups' => array_keys(\App\Support\RequiredStakeholders::groups($team)),
        ] + \App\Support\RequiredStakeholders::lookup($team)])->all();

        return view('documents.create', compact('templates', 'projects', 'brands', 'documentTypes', 'workflowRules', 'templateApproverOptions', 'allUsers', 'allRoles', 'defaultDueHours', 'skus', 'countries', 'categories', 'agencies', 'teams', 'defaultTeam', 'requiredStakeholders', 'chairpersonByBrand'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Document::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'team' => ['nullable', 'string', 'in:' . implode(',', \App\Support\Teams::keys())],
            'category' => ['nullable', 'string', 'in:' . implode(',', Document::CATEGORIES)],
            'brand_id' => ['nullable', 'exists:brands,id'],
            // MOM 24 Nov: "Product" is now the SKU, chosen from the brand's SKU list.
            'sku_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('skus', 'id')->where('status', 'active')],
            'country_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('countries', 'id')->where('status', 'active')],
            'document_type_id' => ['nullable', 'exists:document_types,id'],
            'channel' => ['nullable', 'in:print,digital'],
            'products' => ['nullable', 'string', 'max:500'],
            'countries' => ['nullable', 'string', 'max:500'],
            'target_audience' => ['nullable', 'string', 'in:' . implode(',', array_keys(\App\Support\TargetAudience::LABELS))],
            // Not required server-side: left blank, it's resolved automatically
            // from brand + document type via WorkflowResolver below (spec REQ-26)
            // rather than forcing a manual pick when a rule already covers it.
            'workflow_template_id' => ['nullable', 'exists:workflow_templates,id'],
            // Owner's per-stage approver picks: { "<workflow_stage_id>": ["<user_id>", ...] }.
            // Only honoured when the resolved template has owner_can_customize_workflow
            // = true; each user is validated against that stage's candidate pool below.
            'stage_approvers' => ['nullable', 'array'],
            'stage_approvers.*' => ['array', 'min:1'],
            'stage_approvers.*.*' => ['integer', 'exists:users,id'],
            // Structural customisation of the flow (add / remove / reorder stages),
            // as a JSON string built by the create form's editor. When present it
            // supersedes stage_approvers and the document gets its own private copy
            // of the workflow - see resolveWorkflowEdit() / applyWorkflowEdit().
            'workflow_edit' => ['nullable', 'json'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'start_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // The job's own target date - deliberately allowed past the expiry date.
            'due_date' => ['nullable', 'date'],
            // Per-stage due period in hours (default 48), { "<workflow_stage_id>": hours }.
            'stage_due_hours' => ['nullable', 'array'],
            'stage_due_hours.*' => ['nullable', 'integer', 'min:1', 'max:2160'],
            // Placeholder: start the job without a file; the Design Team uploads the artwork.
            'is_placeholder' => ['nullable', 'boolean'],
            'design_instructions' => ['nullable', 'string', 'max:5000'],
            // Placeholder: who prepares the creative. 'later' (default) = the Brand
            // Manager uploads the Word draft for Content Manager alignment first and
            // commissions the creative once it's aligned.
            'creative_team' => ['nullable', 'in:later,design,agency'],
            'content_agency_id' => ['nullable', 'integer', 'exists:content_agencies,id'],
            'creative_assign_to' => ['nullable', 'integer', 'exists:users,id'],
            // Reference documents (studies, sources) attached at upload time.
            'reference_files' => ['nullable', 'array', 'max:20'],
            'reference_files.*' => ['file', 'max:512000'],
            'aging_warning_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            // Any file type accepted; adjust `max` (in KB) to your infra's real ceiling.
            // For very large files (100MB+), pair this with resumable/chunked upload on the frontend.
            'file' => ['required_unless:is_placeholder,1', 'nullable', 'file', 'max:512000'], // 500MB via standard form upload
        ]);

        // Each team has its own fields: anything the job's team doesn't use is dropped
        // (e.g. brand / SKU / collateral on a Scientific Publications job).
        $team = $validated['team'] ?? \App\Support\Teams::forUser($request->user());
        foreach (['category' => ['category'], 'brand' => ['brand_id'], 'sku' => ['sku_id', 'products'], 'country' => ['country_id', 'countries'],
                  'channel' => ['channel'], 'document_type' => ['document_type_id'], 'target_audience' => ['target_audience']] as $field => $keys) {
            if (! \App\Support\Teams::hasField($team, $field)) {
                foreach ($keys as $key) {
                    $validated[$key] = null;
                }
            }
        }

        $isPlaceholder = (bool) ($validated['is_placeholder'] ?? false);
        $documentType = ! empty($validated['document_type_id']) ? DocumentType::find($validated['document_type_id']) : null;

        if ($documentType && $request->hasFile('file')) {
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
            $brand = ! empty($validated['brand_id']) ? Brand::find($validated['brand_id']) : null;
            $resolved = $this->workflowResolver->resolve($brand, $documentType, $request->user()->department);

            // A collateral type (LBL, Dangler, ...) says what the piece is, not its
            // file format - when no rule covers it, route by the file's format type
            // (PDF/JPG/GIF vs Word/Video/PPT), exactly as those uploads always were.
            if (! $resolved && $request->hasFile('file')) {
                $extension = strtolower($request->file('file')->getClientOriginalExtension());
                $formatType = DocumentType::active()->whereNull('channel')->get()
                    ->first(fn ($t) => ! empty($t->allowed_extensions) && $t->acceptsExtension($extension));
                $resolved = $formatType ? $this->workflowResolver->resolve($brand, $formatType, $request->user()->department) : null;
            }

            $workflowTemplateId = $resolved?->id;
        }

        // Only this team's workflows (an auto-resolved one included).
        if ($workflowTemplateId && (WorkflowTemplate::whereKey($workflowTemplateId)->value('team') ?? 'pharma') !== $team) {
            if (! empty($validated['workflow_template_id'])) {
                return back()->withErrors(['workflow_template_id' => 'That workflow belongs to another team - pick one of the ' . \App\Support\Teams::label($team) . ' workflows.'])->withInput();
            }
            $workflowTemplateId = null;
        }

        if (! $workflowTemplateId) {
            return back()->withErrors(['workflow_template_id' => $team === 'pharma'
                ? 'No workflow could be determined automatically for this Brand/Document Type - please select one.'
                : 'Select one of the ' . \App\Support\Teams::label($team) . ' workflows.'])->withInput();
        }

        // Validate any owner workflow customisation against the resolved template
        // *before* creating anything, so a bad pick fails cleanly with the form
        // re-populated rather than leaving an orphan draft behind. A structural
        // edit (add/remove/reorder) wins over plain approver picks.
        $workflowEdit = $this->resolveWorkflowEdit($workflowTemplateId, $validated['workflow_edit'] ?? null);
        $stageApproverPicks = $workflowEdit
            ? []
            : $this->resolveStageApproverPicks($workflowTemplateId, $validated['stage_approvers'] ?? []);

        // Pharma: the stakeholders selected must include at least one from R&D, Legal
        // and Regulatory Affairs (checked where the Brand Manager selects them).
        $this->assertRequiredStakeholders($team, $workflowTemplateId, $stageApproverPicks, $workflowEdit, $workflowEdit ? 'workflow_edit' : 'stage_approvers');

        $sku = ! empty($validated['sku_id']) ? \App\Models\Sku::find($validated['sku_id']) : null;
        if ($sku && ! empty($validated['brand_id']) && (int) $sku->brand_id !== (int) $validated['brand_id']) {
            return back()->withErrors(['sku_id' => "\"{$sku->name}\" is not a SKU of the selected brand."])->withInput();
        }
        $country = ! empty($validated['country_id']) ? \App\Models\Country::find($validated['country_id']) : null;

        // The master-data picks are the source of truth; the tag columns mirror them
        // so search, AI prompts and older screens keep working.
        $countries = $country ? [$country->name] : $this->splitTags($validated['countries'] ?? null);
        $products = $sku ? [$sku->name] : $this->splitTags($validated['products'] ?? null);

        $document = Document::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'team' => $team,
            'category' => $validated['category'] ?? null,
            'brand_id' => $validated['brand_id'] ?? $sku?->brand_id,
            'sku_id' => $sku?->id,
            'country_id' => $country?->id,
            'document_type_id' => $validated['document_type_id'] ?? null,
            'channel' => $validated['channel'] ?? $documentType?->channel,
            'is_placeholder' => $isPlaceholder,
            'due_date' => $validated['due_date'] ?? null,
            'products' => $products,
            'countries' => $countries,
            'target_audience' => $validated['target_audience'] ?? null,
            'reference_no' => $this->generateReferenceNo($validated['category'] ?? null, $country ? [$country->referencePrefix()] : $countries),
            'owner_id' => $request->user()->id,
            'workflow_template_id' => $workflowTemplateId,
            'project_id' => $validated['project_id'] ?? null,
            'status' => 'draft',
            'start_date' => $validated['start_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'aging_warning_days' => $validated['aging_warning_days'] ?? 30,
        ]);

        $this->audit->record(action: 'DOCUMENT_CREATED', document: $document, actor: $request->user(), newStatus: 'draft');

        if ($stageApproverPicks) {
            $rows = [];
            foreach ($stageApproverPicks as $stageId => $userIds) {
                foreach ($userIds as $userId) {
                    $rows[] = ['workflow_stage_id' => $stageId, 'user_id' => $userId];
                }
            }
            $document->stageApproverSelections()->createMany($rows);

            $this->audit->record(
                action: 'DOCUMENT_UPDATED',
                document: $document,
                actor: $request->user(),
                description: 'Workflow approvers customised at upload for ' . count($stageApproverPicks) . ' stage(s).',
            );
        }

        if ($workflowEdit) {
            $shared = WorkflowTemplate::findOrFail($workflowTemplateId);
            $clone = $shared->clonePrivateFor($document, $request->user());
            $this->applyWorkflowEdit($clone, $workflowEdit);
            $document->update(['workflow_template_id' => $clone->id]);

            $this->audit->record(
                action: 'DOCUMENT_UPDATED',
                document: $document,
                actor: $request->user(),
                description: 'Approval flow customised at upload (stages added / removed / reordered) - private copy of "' . $shared->name . '".',
            );
        }

        $this->saveStageDueHours($document, $validated['stage_due_hours'] ?? []);

        // MOM 24 Nov: the overall due date is worked out from the stages selected.
        app(\App\Services\Workflow\StageSchedule::class)->refreshDueDate($document->fresh());

        foreach ($request->file('reference_files', []) as $referenceFile) {
            $this->storeReference($document, $referenceFile, $request->user());
        }

        if ($isPlaceholder && ! $request->hasFile('file')) {
            $team = $validated['creative_team'] ?? 'later';

            if ($team === 'later') {
                if (filled($validated['design_instructions'] ?? null) && blank($document->description)) {
                    $document->update(['description' => $validated['design_instructions']]);
                }

                return redirect()->route('documents.show', $document)
                    ->with('status', 'Placeholder created. Upload the Word draft and submit it for Content Manager alignment when ready.');
            }

            app(\App\Services\WorkTaskService::class)->requestArtwork(
                document: $document,
                requestedBy: $request->user(),
                instructions: $validated['design_instructions'] ?? null,
                dueAt: now()->addHours(config('promomats.due_dates.default_stage_hours', 48)),
                team: $team,
                agencyId: $team === 'agency' ? ($validated['content_agency_id'] ?? null) : null,
                assignTo: $validated['creative_assign_to'] ?? null,
            );

            return redirect()->route('documents.show', $document)
                ->with('status', 'Placeholder created and sent to the ' . \App\Models\DocumentWorkTask::TEAM_LABELS[$team] . '. You\'ll be asked to confirm their upload before it goes for review.');
        }

        $version = $this->versionService->storeNewVersion(
            document: $document,
            file: $request->file('file'),
            uploader: $request->user(),
            changeNotes: 'Initial upload',
        );

        $this->audit->record(action: 'VERSION_CREATED', document: $document, version: $version, actor: $request->user());

        return redirect()->route('documents.show', $document)->with('status', 'Document uploaded. Submit it for review when ready.');
    }

    /** Attach a reference document (study, source, certificate) to the job. */
    protected function storeReference(Document $document, \Illuminate\Http\UploadedFile $file, User $uploader): void
    {
        $path = $file->storeAs('reference-attachments/documents/' . $document->id, Str::uuid() . '_' . $file->getClientOriginalName(), 'documents');

        $document->referenceAttachments()->create([
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'disk' => 'documents',
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size_bytes' => $file->getSize(),
            'uploaded_by' => $uploader->id,
        ]);
    }

    /**
     * The task owner's per-stage due periods (hours). Only values that differ from
     * the stage's own default are stored; blanks fall back to it.
     *
     * @param  array<int|string, int|string|null>  $raw
     */
    protected function saveStageDueHours(Document $document, array $raw): void
    {
        $stageIds = WorkflowStage::where('workflow_template_id', $document->workflow_template_id)->pluck('sla_hours', 'id');
        $codes = WorkflowStage::whereIn('id', array_keys($raw))->pluck('code', 'id');
        $default = config('promomats.due_dates.default_stage_hours', 48);

        foreach ($raw as $stageId => $hours) {
            $hours = (int) $hours;
            if ($hours < 1) {
                continue;
            }

            // After a structural edit the document runs a private copy: map the
            // shared template's stage onto the copy's stage with the same code.
            $targetId = $stageIds->has((int) $stageId)
                ? (int) $stageId
                : WorkflowStage::where('workflow_template_id', $document->workflow_template_id)->where('code', $codes[$stageId] ?? null)->value('id');

            if (! $targetId || $hours === (int) ($stageIds[$targetId] ?? $default)) {
                continue;
            }

            $document->stageSettings()->updateOrCreate(['workflow_stage_id' => $targetId], ['due_hours' => $hours]);
        }
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
            'activeWorkflowInstance.pendingAssignees.reassignedBy',
            'activeWorkflowInstance.resumeAtStage',
            'workTasks.assignee',
            'workTasks.requester',
            'workTasks.completer',
            'workflowTemplate.stages',
            'comments',
            'pdfAnnotations.resolver',
            'pdfAnnotations.version',
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
            'sku',
            'country',
            'documentType',
            'distributionRecords',
            'stageSettings',
            'stageApproverSelections',
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

        $commentsLocked = $document->commentsLockedFor(request()->user()) || request()->user()->isAgencyUser();

        // MOM 24 Nov: comments shown version by version, including the comments
        // embedded in each Word version; and the approval matrix with due dates.
        $commentsByVersion = $this->commentsByVersion($document);
        $stageSchedule = app(\App\Services\Workflow\StageSchedule::class)->for($document);

        // Page-anchored comment pins, grouped by page number, in the shape the PDF
        // viewer's Alpine component expects: { "1": [ {id, x_position, ...} ], ... }
        $pdfAnnotationsByPage = $document->pdfAnnotations
            ->groupBy('page_number')
            ->mapWithKeys(fn ($group, $page) => [
                (string) $page => $group->map(fn ($c) => [
                    'id' => $c->id,
                    'x_position' => $c->x_position,
                    'y_position' => $c->y_position,
                    'selected_text' => $c->selected_text,
                    'replacement_text' => $c->replacement_text,
                    'highlight_rects' => $c->highlight_rects,
                    'body' => $c->body,
                    'edited' => $c->edited_at !== null,
                    'draft' => $c->published_at === null,
                    'can_edit' => $c->user_id === auth()->id() && ! $commentsLocked,
                    'created_at' => $c->created_at->diffForHumans(),
                    'created_at_iso' => $c->created_at->toIso8601String(),
                    'author' => ['name' => $c->author?->name],
                    'resolved' => $c->resolved_at !== null,
                    'resolved_by' => $c->resolver?->name,
                    // Set only when the pin was made on an earlier version than the one
                    // on screen, so the viewer can flag that it may have drifted.
                    'from_version' => $c->version && $c->document_version_id !== $document->current_version_id
                        ? $c->version->version_no : null,
                    'can_resolve' => in_array(auth()->id(), [$c->user_id, $document->owner_id], true) || auth()->user()->can('access-admin'),
                    'replies' => $c->replies->map(fn ($r) => [
                        'id' => $r->id,
                        'body' => $r->body,
                        'edited' => $r->edited_at !== null,
                        'draft' => $r->published_at === null,
                        'can_edit' => $r->user_id === auth()->id() && ! $commentsLocked,
                        'created_at' => $r->created_at->diffForHumans(),
                        'created_at_iso' => $r->created_at->toIso8601String(),
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
            'claimReferenceMappingsByPage', 'availableProjects', 'observableUsers', 'commentsLocked',
            'commentsByVersion', 'stageSchedule'
        ))->with('aiAvailable', $this->ai->isAvailable())
            ->with('wordEditorRole', WordReviewController::editorRole($document, request()->user()));
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

        // The Content Manager aligned the draft; the Brand Manager now sends the
        // creative (whoever uploaded it) on into review. A fresh upload is optional.
        if ($document->status === 'draft_aligned' && $instance) {
            $document->assertNotOnLegalHold();
            $request->validate(['file' => ['nullable', 'file', 'max:512000']]);

            $version = $request->hasFile('file')
                ? $this->versionService->storeNewVersion($document, $request->file('file'), $request->user(), $request->input('change_notes', 'Creative uploaded by the Brand Manager'))
                : $document->currentVersion;

            $this->engine->continueAfterDraft($instance, $version, $request->user());

            return back()->with('status', 'Review started - it is now with the next stage.');
        }

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
            if (! $document->currentVersion) {
                return back()->withErrors(['file' => 'This placeholder has no artwork yet - it can be submitted once the Design Team (or you) uploads the file.']);
            }
            $this->engine->start($document, $document->currentVersion, $request->user());
        }

        return back()->with('status', 'Document submitted into the approval workflow.');
    }

    /**
     * MOM 24 Nov: after the Chairperson, the Brand Manager gives the final approval
     * and closes the job - optionally uploading a new version first (expected after
     * "Approved with changes"). It then becomes Approved for Production, ready to be
     * submitted for distribution.
     */
    public function close(Request $request, Document $document)
    {
        $this->authorize('close', $document);
        $request->validate([
            'file' => ['nullable', 'file', 'max:512000'],
            'change_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $version = null;
        if ($request->hasFile('file')) {
            $document->assertNotOnLegalHold();
            $version = $this->versionService->storeNewVersion(
                document: $document,
                file: $request->file('file'),
                uploader: $request->user(),
                changeNotes: $request->input('change_notes') ?: 'Final version after the Chairperson sign-off',
            );
            $this->audit->record(action: 'VERSION_CREATED', document: $document, version: $version, actor: $request->user());
        }

        $this->engine->close($document, $request->user(), $version);

        return back()->with('status', 'Final approval given and the job is closed. Submit it for distribution when ready.');
    }

    /**
     * MOM 24 Nov: the Brand Manager (or an admin) moves the due date/time of any
     * stage - the one in progress or one not reached yet. Every change notifies the
     * people on that stage.
     */
    public function updateStageDue(Request $request, Document $document, WorkflowStage $stage)
    {
        $this->authorize('manageTasks', $document);
        abort_unless($stage->workflow_template_id === $document->workflow_template_id, 404);

        $validated = $request->validate(['due_at' => ['required', 'date', 'after:now']]);
        $dueAt = \Illuminate\Support\Carbon::parse($validated['due_at']);
        $schedule = app(\App\Services\Workflow\StageSchedule::class);
        $row = $schedule->for($document)->first(fn ($r) => $r['stage']->id === $stage->id);

        abort_unless($row && in_array($row['state'], ['open', 'upcoming'], true), 422, 'That stage is finished or skipped on this job.');

        $old = $row['due_at'];
        $document->stageSettings()->updateOrCreate(['workflow_stage_id' => $stage->id], ['due_at' => $dueAt]);

        $instance = $document->activeWorkflowInstance;
        if ($row['state'] === 'open' && $instance) {
            $instance->pendingAssignees()->where('workflow_stage_id', $stage->id)->update([
                'due_at' => $dueAt,
                'due_soon_notified_at' => null,
                'overdue_notified_at' => null,
            ]);
        }

        $this->audit->record(
            action: 'DUE_DATE_CHANGED',
            document: $document,
            instance: $instance,
            stage: $stage,
            actor: $request->user(),
            description: "Due date for \"{$stage->name}\" moved from " . ($old?->format('d M Y H:i') ?? '—') . ' to ' . $dueAt->format('d M Y H:i') . ' IST.',
        );

        $schedule->refreshDueDate($document->fresh());

        $recipients = User::whereIn('id', collect($row['assignee_ids'])->push($document->owner_id))
            ->where('is_active', true)
            ->where('id', '!=', $request->user()->id)
            ->get();
        foreach ($recipients as $recipient) {
            $recipient->notify(new DocumentActionNotification(
                document: $document,
                event: 'due_date_changed',
                stage: $stage,
                actor: $request->user(),
                comments: "\"{$stage->name}\" is now due " . $dueAt->format('d M Y, H:i') . ' IST.',
            ));
        }

        return back()->with('status', "Due date for \"{$stage->name}\" updated - the stakeholders have been notified.");
    }

    /**
     * Every comment on the job grouped by the version it was made on, newest version
     * first: general comments and PDF pins (published, or the viewer's own drafts),
     * plus the comments embedded in each Word (.docx) version.
     *
     * @return \Illuminate\Support\Collection<int, array{version: \App\Models\DocumentVersion|null, comments: \Illuminate\Support\Collection, word_comments: array}>
     */
    protected function commentsByVersion(Document $document)
    {
        $comments = \App\Models\DocumentComment::where('document_id', $document->id)
            ->whereNull('parent_id')
            ->with(['author', 'replies.author'])
            ->oldest()
            ->get()
            ->groupBy(fn ($c) => $c->document_version_id ?? 0);

        $extractor = app(\App\Services\WordCommentExtractor::class);

        $groups = $document->versions->map(fn ($v) => [
            'version' => $v,
            'comments' => $comments->get($v->id, collect()),
            'word_comments' => $v->extension() === 'docx' ? $extractor->forVersion($v) : [],
        ]);

        if ($comments->has(0)) {
            $groups->push(['version' => null, 'comments' => $comments->get(0), 'word_comments' => []]);
        }

        return $groups->filter(fn ($g) => $g['comments']->isNotEmpty() || ! empty($g['word_comments']))->values();
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

        // Uploading is allowed at every stage: whoever reviews from here on sees
        // the new file.
        if ($document->activeWorkflowInstance && $document->status === 'in_review') {
            $this->engine->adoptNewVersion($document->activeWorkflowInstance, $version);
        }

        // The artwork for a placeholder has arrived - whoever uploaded it.
        if ($document->is_placeholder) {
            $document->update(['is_placeholder' => false]);
            $document->workTasks()->open()->where('type', 'artwork')->update([
                'status' => 'completed', 'completed_version_id' => $version->id,
                'completed_by' => $request->user()->id, 'completed_at' => now(),
            ]);
        }

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
        $this->authorize('view', $version->document);

        abort_unless(
            \Illuminate\Support\Facades\Storage::disk($version->disk)->exists($version->file_path),
            404,
            'The stored file for this version is missing.'
        );

        $contentType = match (true) {
            $version->isPdf() => 'application/pdf',
            $version->isImage() => $this->safeImageMimeType($version),
            $version->isVideo() => $this->safeVideoMimeType($version),
            default => 'application/octet-stream',
        };

        // Jumping to a video note needs seeking, which browsers can only do when the
        // server honours Range requests - Storage::response() doesn't, a file response does.
        if ($version->isVideo() && config("filesystems.disks.{$version->disk}.driver") === 'local') {
            return response()
                ->file(\Illuminate\Support\Facades\Storage::disk($version->disk)->path($version->file_path), ['Content-Type' => $contentType])
                ->setContentDisposition('inline', $version->original_filename)
                ->setPrivate();
        }

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
     * releasing produced material for distribution, or retiring a document as
     * superseded/obsolete once it's no longer current. "Approved for Production" is
     * never set by hand: only the Chairperson's sign-off (the workflow) sets it.
     */
    public function updateStatus(Request $request, Document $document)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:approved_for_distribution,superseded,obsolete,archived'],
        ]);

        $this->authorize('update', $document);
        $document->assertNotOnLegalHold();

        if ($validated['status'] === 'approved_for_distribution' && $document->status !== 'approved_for_production') {
            throw ValidationException::withMessages(['status' => 'Only material Approved for Production (after the Chairperson) can be released for distribution.']);
        }

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

        if ($validated['status'] === 'approved_for_distribution') {
            $lastInstance = $document->workflowInstances()->latest('id')->first();
            if ($lastInstance) {
                \App\Events\DocumentFinalApproved::dispatch($document, $lastInstance, $request->user());
            }
        }

        return back()->with('status', "Document marked \"{$document->statusLabel()}\".");
    }

    /**
     * Owner (or admin) toggles "let anyone in my department edit this document".
     * Deliberately narrower than DocumentPolicy::update - a granted department
     * editor can edit the document but can't re-open or lock editing for others.
     */
    public function updateEditingAccess(Request $request, Document $document)
    {
        abort_unless(
            $request->user()->id === $document->owner_id || $request->user()->can('access-admin'),
            403,
            'Only the Brand Manager who owns this job or an admin can change editing access.'
        );

        $validated = $request->validate([
            'allow_department_editing' => ['sometimes', 'boolean'],
        ]);

        $document->update([
            'allow_department_editing' => $request->boolean('allow_department_editing'),
        ]);

        $this->audit->record(
            action: 'DOCUMENT_UPDATED',
            document: $document,
            actor: $request->user(),
            description: $document->allow_department_editing
                ? 'Opened editing to the owner\'s department.'
                : 'Restricted editing to owner / admin / Agency.',
        );

        return back()->with('status', $document->allow_department_editing
            ? 'Anyone in your department can now edit this document.'
            : 'Editing is restricted again.');
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
     * Turn the create form's raw `stage_approvers` input into a validated
     * [workflow_stage_id => [user_id, ...]] map, or throw a ValidationException.
     *
     * Picks are only honoured when the template's admin has enabled
     * owner_can_customize_workflow; every chosen user must already be a candidate
     * for that stage (a role holder or a named approver on the template) - the
     * owner narrows a pool to specific people, they can't introduce anyone new.
     *
     * @param  array<int|string, array<int, int|string>>  $raw
     * @return array<int, array<int, int>>
     */
    protected function resolveStageApproverPicks(int $templateId, array $raw): array
    {
        $raw = array_filter($raw, fn ($ids) => ! empty($ids));

        if ($raw === []) {
            return [];
        }

        $template = WorkflowTemplate::with('stages.approvers.role', 'stages.approvers.user')->find($templateId);

        if (! $template?->owner_can_customize_workflow) {
            // Silently ignore stale/forged input for a template that doesn't allow it.
            return [];
        }

        $stagesById = $template->stages->keyBy('id');
        $picks = [];

        foreach ($raw as $stageId => $userIds) {
            $stage = $stagesById->get((int) $stageId);

            if (! $stage) {
                throw ValidationException::withMessages([
                    'stage_approvers' => 'A selected stage does not belong to this workflow.',
                ]);
            }

            $chosen = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();

            // The task owner may choose beyond the stage's usual people (different
            // brands have different Medical / Legal / Regulatory stakeholders) -
            // anyone active who is allowed to take approval tasks.
            $this->assertAssignable($chosen, "\"{$stage->name}\"", 'stage_approvers');

            $picks[$stage->id] = $chosen->all();
        }

        return $picks;
    }

    /**
     * Validate the create form's structural workflow edit against the resolved
     * template and return it as a normalised array, or null when there's no edit
     * to apply. Throws a ValidationException (keyed `workflow_edit`) on anything
     * malformed.
     *
     * Shape returned: ['stages' => [ ['type'=>'existing','code'=>..,'approvers_dirty'=>bool,'user_ids'=>[..]]
     *                               | ['type'=>'new','name'=>..,'approval_mode'=>..,'role_ids'=>[..],'user_ids'=>[..]] ]]
     * in final display order (removed stages already dropped).
     */
    protected function resolveWorkflowEdit(int $templateId, ?string $rawJson): ?array
    {
        if ($rawJson === null || trim($rawJson) === '') {
            return null;
        }

        $decoded = json_decode($rawJson, true);
        $stagesIn = is_array($decoded['stages'] ?? null) ? $decoded['stages'] : null;

        if ($stagesIn === null) {
            return null; // nothing usable - fall back to the default flow
        }

        $template = WorkflowTemplate::with('stages.approvers.role', 'stages.approvers.user')->find($templateId);

        if (! $template?->owner_can_customize_workflow) {
            throw ValidationException::withMessages(['workflow_edit' => 'This workflow may not be customised.']);
        }

        $stagesByCode = $template->stages->keyBy('code');
        $modes = ['any_one', 'all_required', 'majority'];
        $out = [];

        foreach ($stagesIn as $row) {
            if (($row['removed'] ?? false) === true) {
                continue;
            }

            $isNew = ($row['isNew'] ?? false) === true;
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                throw ValidationException::withMessages(['workflow_edit' => 'Every stage needs a name.']);
            }

            $userIds = collect($row['approverUserIds'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $roleIds = collect($row['approverRoleIds'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();

            if ($isNew) {
                $mode = in_array($row['approvalMode'] ?? null, $modes, true) ? $row['approvalMode'] : 'any_one';

                if ($userIds->isEmpty() && $roleIds->isEmpty()) {
                    throw ValidationException::withMessages(['workflow_edit' => "New stage \"{$name}\" needs at least one approver (a role or people)."]);
                }
                if ($roleIds->isNotEmpty() && Role::whereIn('id', $roleIds)->count() !== $roleIds->count()) {
                    throw ValidationException::withMessages(['workflow_edit' => "New stage \"{$name}\" has an unknown role."]);
                }
                if ($userIds->isNotEmpty() && User::whereIn('id', $userIds)->where('is_active', true)->count() !== $userIds->count()) {
                    throw ValidationException::withMessages(['workflow_edit' => "New stage \"{$name}\" has an unknown or inactive person."]);
                }

                $out[] = ['type' => 'new', 'name' => $name, 'approval_mode' => $mode, 'role_ids' => $roleIds->all(), 'user_ids' => $userIds->all()];

                continue;
            }

            $stage = $stagesByCode->get($row['code'] ?? null);

            if (! $stage) {
                throw ValidationException::withMessages(['workflow_edit' => 'A stage in the edit does not belong to this workflow.']);
            }

            $dirty = ($row['approversDirty'] ?? false) === true;

            if ($dirty) {
                if ($userIds->isEmpty()) {
                    throw ValidationException::withMessages(['workflow_edit' => "\"{$stage->name}\" needs at least one approver."]);
                }
                $this->assertAssignable($userIds, "\"{$stage->name}\"", 'workflow_edit');
            }

            $out[] = ['type' => 'existing', 'code' => $stage->code, 'name' => $name, 'approvers_dirty' => $dirty, 'user_ids' => $userIds->all()];
        }

        if ($out === []) {
            throw ValidationException::withMessages(['workflow_edit' => 'The workflow must keep at least one stage.']);
        }

        return ['stages' => $out];
    }

    /**
     * Rewrite a freshly-made private template copy ($clone) to match the owner's
     * structural edit: drop removed stages, add new ones, apply the new order and
     * any approver changes, then rebuild every transition on the "park it back with
     * the owner on AwC/NA" model (the same shape the customisable templates are
     * seeded with).
     *
     * @param  array{stages: array<int, array<string, mixed>>}  $edit
     */
    protected function applyWorkflowEdit(WorkflowTemplate $clone, array $edit): void
    {
        DB::transaction(function () use ($clone, $edit) {
            $keptCodes = collect($edit['stages'])->where('type', 'existing')->pluck('code');

            // Drop removed stages first (cascades their approvers + transitions).
            $clone->stages()->whereNotIn('code', $keptCodes)->get()
                ->each(fn ($s) => $s->delete());

            // Park sequence numbers out of the way so the per-template
            // (template_id, sequence_no) unique index can't trip while we reshuffle,
            // then re-read the stages so their in-memory "original" reflects the
            // parked values (otherwise Eloquent's dirty check can skip a write when
            // a stage's new position happens to equal its pre-park number).
            $clone->stages()->update(['sequence_no' => DB::raw('sequence_no + 1000')]);
            $cloneStagesByCode = $clone->stages()->get()->keyBy('code');

            $ordered = [];
            $seq = 0;

            foreach ($edit['stages'] as $row) {
                $seq++;

                if ($row['type'] === 'new') {
                    $stage = $clone->stages()->create([
                        'sequence_no' => $seq,
                        'name' => $row['name'],
                        'code' => 'CUSTOM_' . $seq . '_' . strtoupper(Str::random(4)),
                        'approval_mode' => $row['approval_mode'],
                    ]);
                    foreach ($row['role_ids'] as $rid) {
                        $stage->approvers()->create(['role_id' => $rid]);
                    }
                    foreach ($row['user_ids'] as $uid) {
                        $stage->approvers()->create(['user_id' => $uid]);
                    }
                } else {
                    $stage = $cloneStagesByCode->get($row['code']);
                    $stage->update(['sequence_no' => $seq, 'name' => $row['name']]);

                    if ($row['approvers_dirty']) {
                        $stage->approvers()->delete();
                        foreach ($row['user_ids'] as $uid) {
                            $stage->approvers()->create(['user_id' => $uid]);
                        }
                    }
                }

                $ordered[] = $stage->fresh();
            }

            $this->rebuildTransitions($ordered);
        });
    }

    /**
     * Wire a fresh set of transitions for a customised private flow with the
     * standard routing rules - see App\Support\Workflow\TransitionWiring.
     *
     * @param  array<int, WorkflowStage>  $ordered
     */
    protected function rebuildTransitions(array $ordered): void
    {
        $ids = collect($ordered)->pluck('id');
        WorkflowStage::whereIn('id', $ids)->update(['is_final_distribution_stage' => false]);
        if ($last = end($ordered)) {
            $last->update(['is_final_distribution_stage' => true]);
        }

        \App\Support\Workflow\TransitionWiring::wire(collect($ordered)->map->fresh());
    }

    /**
     * Every chosen person must be an active user who may take approval tasks
     * (Brand Managers start and assign jobs but never approve; the job's own owner
     * never reviews it).
     *
     * @param  \Illuminate\Support\Collection<int, int>  $userIds
     */
    /**
     * The people a new job's workflow will reach - the picks, the edited flow, or
     * (nothing picked) every stage's own pool - must cover the team's required
     * stakeholder groups. Only for workflows the Brand Manager selects people on.
     */
    protected function assertRequiredStakeholders(string $team, int $templateId, array $picks, ?array $edit, string $errorKey): void
    {
        if (\App\Support\RequiredStakeholders::groups($team) === []) {
            return;
        }

        $template = WorkflowTemplate::with('stages.approvers.role', 'stages.approvers.user')->find($templateId);
        if (! $template?->owner_can_customize_workflow) {
            return;
        }

        if ($edit) {
            $stagesByCode = $template->stages->keyBy('code');
            $ids = collect($edit['stages'])->flatMap(function ($row) use ($stagesByCode) {
                if ($row['type'] === 'new') {
                    $roleHolders = $row['role_ids']
                        ? User::whereHas('roles', fn ($q) => $q->whereIn('roles.id', $row['role_ids']))->where('is_active', true)->pluck('id')
                        : collect();

                    return $roleHolders->merge($row['user_ids']);
                }

                return $row['approvers_dirty'] ? $row['user_ids'] : ($stagesByCode->get($row['code'])?->candidateUserIds() ?? collect());
            });
        } elseif ($picks) {
            $ids = collect($picks)->flatten();
        } else {
            $ids = $template->stages->flatMap->candidateUserIds();
        }

        // Nobody reviews their own job, and Brand Managers never get decision tasks.
        $ids = User::with('roles')->whereIn('id', $ids->unique()->all())->where('is_active', true)->get()
            ->reject(fn (User $u) => $u->id === auth()->id() || $u->isBrandManagerOnly())
            ->pluck('id');

        $missing = \App\Support\RequiredStakeholders::missing($team, $ids);
        if ($missing) {
            $all = array_keys(\App\Support\RequiredStakeholders::groups($team));
            throw ValidationException::withMessages([$errorKey => \App\Support\Teams::label($team) . ' jobs need at least one stakeholder from '
                . \App\Support\RequiredStakeholders::sentence($all) . ' - please select someone for ' . \App\Support\RequiredStakeholders::sentence($missing) . '.']);
        }
    }

    protected function assertAssignable($userIds, string $label, string $errorKey): void
    {
        $users = User::with('roles')->whereIn('id', $userIds)->where('is_active', true)->get();

        if ($users->count() !== $userIds->count()) {
            throw ValidationException::withMessages([$errorKey => "{$label} was given an unknown or inactive person."]);
        }

        if ($bm = $users->first(fn ($u) => $u->isBrandManagerOnly())) {
            throw ValidationException::withMessages([$errorKey => "{$label}: {$bm->name} is a Brand Manager and can't approve or reject."]);
        }

        // The person creating the job owns it - makers can't be checkers.
        if ($self = $users->firstWhere('id', auth()->id())) {
            throw ValidationException::withMessages([$errorKey => "{$label}: you own this job, so you can't also review it."]);
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
