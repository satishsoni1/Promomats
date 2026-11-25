@php
    $instance = $document->activeWorkflowInstance;
    $isOwner = auth()->id() === $document->owner_id;
    $isPendingApprover = $instance && $instance->pendingAssignees->contains('user_id', auth()->id());
    $myAssignment = $isPendingApprover ? $instance->pendingAssignees->firstWhere('user_id', auth()->id()) : null;

    // Stage progress stepper state. $openRuns is every stage currently in
    // progress - normally one, but a parallel_group opens several at once (see
    // WorkflowEngine's fan-out/fan-in) - so the stepper highlights all of them
    // as "current" rather than just current_stage_id's single primary stage.
    $stages = $document->workflowTemplate?->stages ?? collect();
    $openRuns = $instance ? $instance->stageRuns()->where('status', 'open')->with('stage')->orderBy('workflow_stage_id')->get() : collect();
    $openSeqs = $openRuns->pluck('stage.sequence_no');
    $currentSeq = $instance?->currentStage?->sequence_no;
    $isRejected = $document->status === 'rejected';
    $isFullyApproved = in_array($document->status, ['approved', 'approved_for_production', 'approved_for_distribution']);
    $lastActionSeq = $document->approvalActions->first()?->stage?->sequence_no;
    // Stages resolved without anyone ever being assigned were skipped (an MLR track
    // the Brand Manager didn't pick, or a stage whose condition didn't apply).
    $skippedStageIds = $instance
        ? $instance->stageRuns->where('status', 'resolved')->pluck('workflow_stage_id')
            ->diff($instance->assignees()->pluck('workflow_stage_id'))->values()
        : collect();

    $cardClass = 'bg-white rounded-xl border border-gray-200/80 p-6';

    $user = auth()->user();
    $canManageTasks = $user->can('manageTasks', $document);
    $canUploadVersion = $user->can('uploadVersion', $document);
    $openWorkTask = $document->workTasks->firstWhere('status', 'open');
    $isParked = $document->status === 'approved_with_changes_pending';
    // Content Manager aligned the draft: back with the Brand Manager to commission the creative.
    $isDraftAligned = $document->status === 'draft_aligned';
    $isBackWithOwner = $isParked || $isDraftAligned;
    $requirePassword = config('promomats.approvals.require_password', false);
    $myStageIsDraft = $myAssignment?->stage?->isDraft();
    $myStageIsOffline = $myAssignment?->stage?->isOffline();
    $myStageIsFinal = $myAssignment && ! $myAssignment->stage->nextStage();
    // Agency logins read the comments but don't take part in the review.
    $commentsLocked = $document->commentsLockedFor($user) || $user->isAgencyUser();
    $commentsAreDraft = $document->commentsStartAsDraftFor($user);
    $firstStageIsDraft = (bool) $document->workflowTemplate?->stages->first()?->isDraft();
    // A file uploaded since the job came back (e.g. by the Content Agency) for the Brand Manager to confirm.
    $uploadToConfirm = $isBackWithOwner && $instance && $document->current_version_id !== $instance->document_version_id
        ? $document->currentVersion : null;
    $reassignOptions = $canManageTasks && $instance
        ? \App\Models\User::with('roles')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->filter(fn ($u) => $u->canRecordDecisions() && $u->id !== $document->owner_id)
        : collect();
    $teamMembers = $canManageTasks
        ? ['design' => \App\Models\User::teamMembers('design')->get(['id', 'name']), 'agency' => \App\Models\User::teamMembers('agency')->get(['id', 'name'])]
        : ['design' => collect(), 'agency' => collect()];
    $lastDecision = $document->approvalActions->first();
    // "Approved for Production" comes only from the Chairperson sign-off; once
    // produced, the owner can release it for distribution.
    $lifecycleActions = ($document->status === 'approved_for_production' ? ['approved_for_distribution' => 'Submit for Distribution'] : [])
        + ['superseded' => 'Mark Superseded', 'obsolete' => 'Mark Obsolete', 'archived' => 'Archive'];

    // MOM 24 Nov.
    // External agency logins see the job and its comments, never the workflow.
    $isAgencyUser = $user->isAgencyUser();
    // Stages nobody was selected for are hidden from the approval matrix.
    $hiddenStageIds = $stageSchedule->where('state', 'skipped')->map(fn ($r) => $r['stage']->id)->values();
    $hiddenStageNames = $stageSchedule->where('state', 'skipped')->map(fn ($r) => $r['stage']->name)->values();
    $scheduleUserNames = \App\Models\User::whereIn('id', $stageSchedule->flatMap(fn ($r) => $r['assignee_ids'])->unique())->pluck('name', 'id');
    // Chairperson signed off: waiting on the Brand Manager's final approval & closure.
    $isPendingClosure = $document->status === 'pending_closure';
    $closureDecision = $isPendingClosure ? $document->approvalActions->first() : null;
    $agencies = $canManageTasks
        ? \App\Models\ContentAgency::active()->orderBy('name')->with(['users' => fn ($q) => $q->where('is_active', true)->orderBy('name')])->get()
        : collect();
    $commentCount = $commentsByVersion->sum(fn ($g) => $g['comments']->count() + $g['comments']->sum(fn ($c) => $c->replies->count()) + count($g['word_comments']));
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight truncate">{{ $document->title }}</h2>
                <div class="flex flex-wrap items-center gap-1.5 mt-0.5">
                    <p class="text-xs text-gray-500 font-mono">{{ $document->reference_no }}</p>
                    @if (! $isAgencyUser && ($document->products || $document->countries))
                        <span class="text-gray-300">&middot;</span>
                        @foreach ($document->products ?? [] as $p)<span class="inline-block px-1.5 py-0.5 bg-gray-100 rounded text-[11px] text-gray-600">{{ $p }}</span>@endforeach
                        @foreach ($document->countries ?? [] as $c)<span class="inline-block px-1.5 py-0.5 bg-gray-100 rounded text-[11px] text-gray-600">{{ $c }}</span>@endforeach
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                @unless ($isAgencyUser)
                <form method="POST" action="{{ route('basket.add', $document) }}">
                    @csrf
                    <button class="text-xs text-gray-500 hover:text-brand-600 border border-gray-200 rounded-lg px-2.5 py-1.5 hover:border-brand-200 transition">+ Basket</button>
                </form>
                @endunless
                <x-status-badge :status="$document->status" class="!text-xs !px-3 !py-1.5">{{ $document->statusLabel() }}</x-status-badge>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($document->legal_hold)
                <div class="bg-[#fdf1f1] border-2 border-[#d03b3b] rounded-xl p-4 flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <span class="text-xl leading-none">⚖️</span>
                        <div>
                            <p class="font-semibold text-[#8f2323]">Legal Hold — this document is frozen</p>
                            <p class="text-sm text-[#8f2323]/90 mt-0.5">
                                No new versions, content edits, or archiving are permitted while this hold is active.
                                @if ($document->legal_hold_reason)
                                    <span class="block mt-1"><strong>Reason:</strong> {{ $document->legal_hold_reason }}</span>
                                @endif
                                <span class="block mt-1 text-xs text-[#8f2323]/70">
                                    Set by {{ $document->legalHoldSetBy?->name ?? '—' }} on {{ $document->legal_hold_set_at?->format('M j, Y g:i A') }}
                                </span>
                            </p>
                        </div>
                    </div>
                    @if (auth()->user()->can('access-admin'))
                        <form method="POST" action="{{ route('documents.legal-hold.release', $document) }}" class="shrink-0" onsubmit="return confirm('Release the legal hold on this document?');">
                            @csrf
                            @method('DELETE')
                            <button class="text-xs font-medium text-[#8f2323] border border-[#d03b3b]/50 rounded-lg px-3 py-1.5 hover:bg-[#d03b3b]/10 whitespace-nowrap">Release Hold</button>
                        </form>
                    @endif
                </div>
            @elseif (auth()->user()->can('access-admin'))
                <div x-data="{ open: false }" class="flex justify-end">
                    <button @click="open = true" type="button" class="text-xs text-gray-500 hover:text-[#d03b3b] border border-gray-200 rounded-lg px-2.5 py-1.5">⚖️ Place Legal Hold</button>
                    <div x-show="open" x-cloak class="fixed inset-0 bg-black/30 flex items-center justify-center z-50" style="display: none;">
                        <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-md" @click.outside="open = false">
                            <h3 class="font-semibold text-gray-900 mb-1">Place Legal Hold</h3>
                            <p class="text-xs text-gray-500 mb-3">Freezes this document — no new versions, content edits, or archiving until released.</p>
                            <form method="POST" action="{{ route('documents.legal-hold.place', $document) }}">
                                @csrf
                                <textarea name="reason" rows="3" required placeholder="Reason (e.g. litigation hold ref #, regulatory inquiry ID)…" class="block w-full text-sm border-gray-300 rounded-lg"></textarea>
                                <div class="flex justify-end gap-2 mt-3">
                                    <button type="button" @click="open = false" class="text-xs text-gray-500 px-3 py-1.5">Cancel</button>
                                    <button type="submit" class="text-xs font-medium text-white bg-[#d03b3b] rounded-lg px-3 py-1.5 hover:bg-[#b83232]">Place Hold</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif

            @if ($stages->isNotEmpty() && ! $isAgencyUser)
                <div class="{{ $cardClass }} !p-4 overflow-x-auto">
                    <div class="flex items-center min-w-max">
                        @php $stageColumns = $stages->reject(fn ($s) => $hiddenStageIds->contains($s->id))->groupBy(fn ($s) => $s->parallel_group ?: 'solo-' . $s->id); @endphp
                        @foreach ($stageColumns as $column)
                            <div class="flex items-center {{ ! $loop->last ? 'flex-1' : '' }}">
                            <div class="flex flex-col gap-1.5 px-2 {{ $column->count() > 1 ? 'border border-dashed border-brand-200 rounded-lg py-1.5' : '' }}">
                            @if ($column->count() > 1)
                                <span class="text-[9px] uppercase tracking-wider text-brand-600 text-center">in parallel</span>
                            @endif
                            @foreach ($column as $stage)
                            @php
                                $state = 'upcoming';
                                if ($isFullyApproved || $isPendingClosure) {
                                    $state = 'done';
                                } elseif ($isRejected) {
                                    $state = $lastActionSeq && $stage->sequence_no <= $lastActionSeq ? 'rejected' : 'upcoming';
                                } elseif ($skippedStageIds->contains($stage->id)) {
                                    $state = 'skipped';
                                } elseif ($openSeqs->contains($stage->sequence_no)) {
                                    $state = 'current';
                                } elseif ($isParked && $instance && ($stage->parallel_group ? $stage->parallel_group === $instance->currentStage?->parallel_group : $stage->id === $instance->current_stage_id)) {
                                    $state = 'returned';
                                } elseif ($currentSeq && $stage->sequence_no < $currentSeq) {
                                    $state = 'done';
                                } elseif ($instance && $instance->stageRuns->where('workflow_stage_id', $stage->id)->where('status', 'resolved')->isNotEmpty()) {
                                    $state = 'done';
                                }
                                $pill = match ($state) {
                                    'done' => 'bg-brand-600 text-white',
                                    'current' => 'bg-accent-500 text-white ring-4 ring-accent-100',
                                    'rejected' => 'bg-red-500 text-white',
                                    'returned' => 'bg-amber-400 text-white ring-4 ring-amber-100',
                                    'skipped' => 'bg-white text-gray-300 border border-dashed border-gray-300',
                                    default => 'bg-gray-200 text-gray-500',
                                };
                                $label = match ($state) {
                                    'done' => 'text-brand-700 font-medium',
                                    'current' => 'text-accent-700 font-semibold',
                                    'rejected' => 'text-red-600 font-medium',
                                    'returned' => 'text-amber-700 font-semibold',
                                    'skipped' => 'text-gray-300 line-through',
                                    default => 'text-gray-400',
                                };
                            @endphp
                                <div class="flex items-center gap-1.5 {{ $column->count() > 1 ? '' : 'flex-col' }}">
                                    <div class="w-7 h-7 shrink-0 rounded-full flex items-center justify-center text-xs font-bold {{ $pill }}" title="{{ $state === 'returned' ? 'Back with the Brand Manager' : ($state === 'skipped' ? 'Skipped - no stakeholder selected' : '') }}">
                                        {{ $state === 'done' ? '✓' : ($state === 'skipped' ? '–' : $stage->sequence_no) }}
                                    </div>
                                    <span class="text-[11px] whitespace-nowrap {{ $label }}">{{ $stage->name }}</span>
                                    @if ($state === 'skipped')<span class="text-[9px] uppercase tracking-wide text-gray-400">skipped</span>@endif
                                </div>
                            @endforeach
                            </div>
                            @if (! $loop->last)
                                <div class="flex-1 h-0.5 min-w-[2rem] {{ isset($state) && $state === 'done' ? 'bg-brand-600' : 'bg-gray-200' }}"></div>
                            @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($hiddenStageNames->isNotEmpty())
                        <p class="mt-2 text-[11px] text-gray-400" title="Skipped - no stakeholder selected">Not in this job's approval matrix (no stakeholder selected): {{ $hiddenStageNames->join(', ') }}</p>
                    @endif
                </div>
            @endif

            @if ($isAgencyUser)
                {{-- Agency logins don't see the workflow status banners. --}}
            @elseif ($isPendingClosure)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <strong>{{ $closureDecision?->decision === 'approved_with_changes' ? 'Chairperson approved with changes' : 'Chairperson approved' }}{{ $closureDecision ? ' — ' . $closureDecision->acted_at?->diffForHumans() : '' }}.</strong>
                    Waiting on {{ $document->owner?->name }} (Brand Manager) for the final approval and closure{{ $closureDecision?->decision === 'approved_with_changes' ? ' — a new version can be uploaded first' : '' }}. It becomes Approved for Production once closed.
                </div>
            @elseif ($document->is_placeholder)
                <div class="rounded-xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-900">
                    <strong>Placeholder</strong> — waiting for the artwork.
                    @if ($openWorkTask)
                        With the {{ $openWorkTask->teamLabel() }}{{ $openWorkTask->assignee ? ' (' . $openWorkTask->assignee->name . ')' : ', not yet assigned' }}{{ $openWorkTask->due_at ? ', due ' . $openWorkTask->due_at->format('d M Y, H:i') : '' }}.
                    @else
                        {{ $document->owner?->name }} (Brand Manager) uploads the Word draft for Content Manager alignment, or assigns the creative to the Content Agency / Design Team.
                    @endif
                </div>
            @elseif ($isDraftAligned)
                <div class="rounded-xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-900">
                    <strong>Draft aligned{{ $lastDecision ? ' by ' . $lastDecision->actor?->name . ' ' . $lastDecision->acted_at?->diffForHumans() : '' }}.</strong>
                    @if ($openWorkTask)
                        The creative is with the {{ $openWorkTask->teamLabel() }}{{ $openWorkTask->assignee ? ' (' . $openWorkTask->assignee->name . ')' : '' }}; it comes back to {{ $document->owner?->name }} (Brand Manager) to confirm.
                    @else
                        Waiting on {{ $document->owner?->name }} (Brand Manager) to assign the creative and start the review{{ $instance?->resumeAtStage ? ' at "' . $instance->resumeAtStage->name . '"' : '' }}.
                    @endif
                </div>
            @elseif ($isParked)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <strong>Waiting on {{ $openWorkTask ? 'the ' . $openWorkTask->teamLabel() . ($openWorkTask->assignee ? ' (' . $openWorkTask->assignee->name . ')' : '') : $document->owner?->name . ' (Brand Manager)' }}</strong>
                    to upload the revision{{ $lastDecision ? ' — ' . $lastDecision->decisionLabel() . ' by ' . $lastDecision->actor?->name . ' at ' . $lastDecision->stage?->name . ', ' . $lastDecision->acted_at?->diffForHumans() : '' }}.
                    @if ($lastDecision?->decision === 'not_approved')
                        Once resubmitted it goes back to {{ $instance?->resumeAtStage?->name ?? $lastDecision->stage?->name }}{{ $instance?->resumeAtStage && $instance->resumeAtStage->id !== $lastDecision->stage?->id ? ' and through every stage after it again' : '' }}.
                    @elseif ($lastDecision?->decision === 'approved_with_changes' && ! $lastDecision->stage?->isDraft() && ($document->workflowTemplate?->awc_resume ?? 'next_stage') === 'next_stage')
                        Once it's uploaded the workflow completes.
                    @endif
                </div>
            @endif

            @if ($commentsLocked)
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                    🔒 Your stage has given its decision, so you can no longer add or edit comments on this job — you can still view it. It unlocks only if the job comes back to you with a new task.
                </div>
            @elseif ($commentsAreDraft)
                <div class="rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">
                    ✎ Your comments are <strong>drafts</strong> — only you can see them until you submit your review.
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left: details + versions + history -->
                <div class="lg:col-span-2 space-y-6 min-w-0">

                    @if ($document->currentVersion?->isPdf() || ($document->currentVersion?->isImage() && $document->currentVersion?->extension() !== 'svg'))
                        @include('documents.partials.pdf-viewer')
                    @elseif ($document->currentVersion?->isVideo())
                        @include('documents.partials.video-viewer')
                    @elseif ($document->currentVersion?->isWordDocument())
                        @include('documents.partials.word-viewer')
                    @elseif ($document->currentVersion?->isImage())
                        <div class="{{ $cardClass }}">
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-base font-semibold text-gray-900">Preview</h3>
                                <span class="text-xs text-gray-400">Click to enlarge</span>
                            </div>
                            <img src="{{ $document->currentVersion->viewUrl() }}" alt="{{ $document->title }}"
                                 class="max-h-[70vh] rounded-lg border border-gray-100 cursor-zoom-in mx-auto"
                                 onclick="this.classList.toggle('fixed');this.classList.toggle('inset-0');this.classList.toggle('z-50');this.classList.toggle('m-auto');this.classList.toggle('max-h-[95vh]');this.classList.toggle('max-w-[95vw]');this.classList.toggle('cursor-zoom-out');this.classList.toggle('shadow-2xl');">
                        </div>
                    @endif

                    <div class="{{ $cardClass }}">
                        <h3 class="text-base font-semibold text-gray-900 mb-4">Details</h3>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-4 text-sm">
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Owner</dt>
                                <dd class="text-gray-900 flex items-center gap-1.5">
                                    <x-avatar :name="$document->owner?->name" size="xs" />
                                    {{ $document->owner?->name }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Team</dt>
                                <dd class="text-gray-900">{{ $document->teamLabel() }}</dd>
                            </div>
                            @if (\App\Support\Teams::hasField($document->team, 'channel'))
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Collateral</dt>
                                <dd class="text-gray-900">
                                    {{ collect([$document->channelLabel(), $document->documentType?->name])->filter()->join(' · ') ?: '—' }}
                                </dd>
                            </div>
                            @endif
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Task due date</dt>
                                <dd class="text-gray-900 {{ $document->due_date?->isPast() && ! in_array($document->status, \App\Models\Document::LIBRARY_STATUSES) ? 'text-red-600' : '' }}">{{ $document->due_date?->format('d M Y') ?? '—' }}</dd>
                            </div>
                            @if (\App\Support\Teams::hasField($document->team, 'brand'))
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Category</dt>
                                <dd class="text-gray-900">{{ $document->category ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Brand / SKU</dt>
                                <dd class="text-gray-900">{{ collect([$document->brand?->name, $document->sku?->name ?? collect($document->products)->join(', ')])->filter()->join(' · ') ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Country</dt>
                                <dd class="text-gray-900">{{ $document->country?->name ?? (collect($document->countries)->join(', ') ?: '—') }}</dd>
                            </div>
                            @endif
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Target Audience</dt>
                                <dd class="text-gray-900">
                                    @if ($document->targetAudienceLabel())
                                        {{ $document->targetAudienceLabel() }}
                                        @php $guidance = \App\Support\TargetAudience::guidance($document->target_audience); @endphp
                                        @if ($guidance)
                                            <span @class([
                                                'ml-1 text-[10px] px-1.5 py-0.5 rounded-full font-medium',
                                                'bg-red-100 text-red-700' => $guidance['depth'] === 'high',
                                                'bg-amber-100 text-amber-700' => $guidance['depth'] === 'medium',
                                                'bg-gray-100 text-gray-500' => $guidance['depth'] === 'low',
                                            ])>{{ $guidance['depth'] === 'high' ? 'Deep review' : ($guidance['depth'] === 'medium' ? 'Standard review' : 'Light review') }}</span>
                                        @endif
                                    @else
                                        <span class="text-gray-400">Not specified</span>
                                    @endif
                                </dd>
                            </div>
                            @unless ($isAgencyUser)
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Workflow</dt>
                                <dd class="text-gray-900">
                                    @if ($document->workflowTemplate?->is_private)
                                        {{ $document->workflowTemplate->derivedFrom?->name ?? $document->workflowTemplate->name }}
                                        <span class="ml-1 text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">Customised</span>
                                    @else
                                        {{ $document->workflowTemplate?->name ?? '—' }}
                                    @endif
                                </dd>
                            </div>
                            @endunless
                            @if ($isOwner || auth()->user()->can('access-admin'))
                                <div>
                                    <dt class="text-xs text-gray-400 mb-0.5">Editing access</dt>
                                    <dd class="text-gray-900">
                                        <form method="POST" action="{{ route('documents.editing-access.update', $document) }}" class="inline-flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="allow_department_editing" value="0">
                                            <label class="inline-flex items-center gap-2 text-sm">
                                                <input type="checkbox" name="allow_department_editing" value="1" @checked($document->allow_department_editing) onchange="this.form.submit()" class="rounded border-gray-300 text-brand-600">
                                                Anyone in {{ $document->owner?->department ?: 'the owner\'s department' }} can edit
                                            </label>
                                        </form>
                                        <p class="text-xs text-gray-400 mt-0.5">Off: only you, an admin, or Agency. Submitting into the workflow always stays with you.</p>
                                    </dd>
                                </div>
                            @elseif ($document->allow_department_editing)
                                <div>
                                    <dt class="text-xs text-gray-400 mb-0.5">Editing access</dt>
                                    <dd class="text-gray-900 text-sm">Open to {{ $document->owner?->department ?: 'the owner\'s department' }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Project</dt>
                                <dd class="text-gray-900">
                                    @if ($document->project)
                                        <a href="{{ route('projects.show', $document->project) }}" class="text-brand-600 hover:underline">{{ $document->project->name }}</a>
                                    @else
                                        <span class="text-gray-400">Unassigned</span>
                                    @endif
                                    @if ($isOwner || auth()->user()->can('access-admin'))
                                        <button type="button" onclick="document.getElementById('project-assign-form').classList.toggle('hidden')" class="text-xs text-brand-600 hover:underline ml-1">Change</button>
                                    @endif
                                </dd>
                            </div>
                            @if ($document->project)
                                <div>
                                    <dt class="text-xs text-gray-400 mb-0.5">Cycle</dt>
                                    <dd class="text-gray-900">
                                        @if ($document->cycle)
                                            <a href="{{ route('projects.cycles.show', [$document->project, $document->cycle]) }}" class="text-brand-600 hover:underline">{{ $document->cycle->name }}</a>
                                        @else
                                            <span class="text-gray-400">Unassigned</span>
                                        @endif
                                        @if ($isOwner || auth()->user()->can('access-admin'))
                                            <button type="button" onclick="document.getElementById('cycle-assign-form').classList.toggle('hidden')" class="text-xs text-brand-600 hover:underline ml-1">Change</button>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-xs text-gray-400 mb-0.5">Start / Expiry</dt>
                                <dd class="text-gray-900">
                                    {{ $document->start_date?->toFormattedDateString() ?? '—' }}
                                    &rarr;
                                    {{ $document->expiry_date?->toFormattedDateString() ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                        @if ($isOwner || auth()->user()->can('access-admin'))
                            <form id="project-assign-form" method="POST" action="{{ route('documents.project.update', $document) }}" class="hidden mt-4 p-3 bg-gray-50 rounded-lg flex items-center gap-2">
                                @csrf
                                <select name="project_id" class="text-sm border-gray-300 rounded-lg flex-1">
                                    <option value="">— No project —</option>
                                    @foreach ($availableProjects as $project)
                                        <option value="{{ $project->id }}" @selected($document->project_id === $project->id)>{{ $project->name }}</option>
                                    @endforeach
                                </select>
                                <x-primary-button type="submit">Save</x-primary-button>
                            </form>
                            @if ($document->project)
                                <form id="cycle-assign-form" method="POST" action="{{ route('documents.cycle.update', $document) }}" class="hidden mt-4 p-3 bg-gray-50 rounded-lg flex items-center gap-2">
                                    @csrf
                                    <select name="cycle_id" class="text-sm border-gray-300 rounded-lg flex-1">
                                        <option value="">— No cycle —</option>
                                        @foreach ($document->project->cycles as $cycle)
                                            <option value="{{ $cycle->id }}" @selected($document->cycle_id === $cycle->id)>{{ $cycle->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-primary-button type="submit">Save</x-primary-button>
                                </form>
                            @endif
                        @endif
                        @if ($document->description)
                            <p class="mt-4 pt-4 border-t border-gray-100 text-sm text-gray-600 leading-relaxed">{{ $document->description }}</p>
                        @endif
                    </div>

                    <div class="{{ $cardClass }}" x-data="versionPreview()" @keydown.escape.window="closePreview()">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-base font-semibold text-gray-900">Versions</h3>
                            <div class="flex items-center gap-3">
                                @if ($document->legal_hold)
                                    <span class="text-xs text-[#8f2323]" title="Content is frozen while this document is under legal hold">⚖️ Locked (Legal Hold)</span>
                                @else
                                    @if ($canUploadVersion && ! $isAgencyUser)
                                        <button type="button" onclick="document.getElementById('upload-version-form').classList.toggle('hidden')" class="text-sm text-brand-600 hover:underline">+ Upload new version</button>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if ($canUploadVersion && ! $isAgencyUser)
                            <form id="upload-version-form" method="POST" action="{{ route('documents.versions.store', $document) }}" enctype="multipart/form-data" class="hidden mb-4 p-4 bg-gray-50 rounded-lg space-y-3">
                                @csrf
                                @if ($document->status === 'in_review')
                                    <p class="text-xs text-gray-500">The review carries on with this new version — current reviewers are notified and see it straight away.</p>
                                @endif
                                <x-file-dropzone name="file" required />
                                <input type="text" name="change_notes" placeholder="Change notes" class="block w-full text-sm border-gray-300 rounded-lg">
                                <x-primary-button>Upload Version</x-primary-button>
                            </form>
                        @endif

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm divide-y divide-gray-100">
                                <thead>
                                    <tr class="text-left text-gray-400 text-xs uppercase tracking-wide">
                                        <th class="py-2 font-medium">Ver.</th>
                                        <th class="py-2 font-medium">File</th>
                                        <th class="py-2 font-medium">Uploaded by</th>
                                        <th class="py-2 font-medium">Size</th>
                                        <th class="py-2 font-medium">Notes</th>
                                        <th class="py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($document->versions as $version)
                                        <tr class="hover:bg-gray-50/70">
                                            <td class="py-2.5 font-medium text-gray-700">v{{ $version->version_no }} @if($version->is_current)<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-medium">current</span>@endif</td>
                                            <td class="py-2.5 text-gray-700">{{ $version->original_filename }}</td>
                                            <td class="py-2.5 text-gray-500">{{ $version->uploader?->name }}</td>
                                            <td class="py-2.5 text-gray-500">{{ $version->humanFileSize() }}</td>
                                            <td class="py-2.5 text-gray-400">{{ $version->change_notes }}</td>
                                            <td class="py-2.5 text-right whitespace-nowrap">
                                                <button type="button"
                                                        @click="showVersion({{ \Illuminate\Support\Js::from([
                                                            'url' => $version->viewUrl(),
                                                            'downloadUrl' => $version->downloadUrl(),
                                                            'name' => $version->original_filename,
                                                            'label' => 'v' . $version->version_no . ($version->is_current ? ' (current)' : ''),
                                                            'kind' => $version->previewKind(),
                                                        ]) }})"
                                                        class="text-brand-600 hover:underline font-medium">View</button>
                                                <span class="text-gray-300 mx-1">|</span>
                                                <a href="{{ $version->downloadUrl() }}" class="text-gray-500 hover:text-brand-600 hover:underline">Download</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- In-page preview: look at any version without downloading it. --}}
                        <template x-teleport="body">
                            <div x-show="current" x-cloak class="fixed inset-0 z-50 bg-gray-900/70 flex items-center justify-center p-2 sm:p-6" @click.self="closePreview()">
                                <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl h-full flex flex-col overflow-hidden">
                                    <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-gray-100">
                                        <div class="min-w-0">
                                            <p class="font-semibold text-gray-900 truncate" x-text="current?.name"></p>
                                            <p class="text-xs text-gray-500" x-text="current?.label"></p>
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <a :href="current?.downloadUrl" class="inline-flex items-center gap-1 px-3 py-1.5 border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-gray-50">⬇ Download</a>
                                            <button type="button" @click="closePreview()" class="w-8 h-8 rounded-lg text-gray-500 hover:bg-gray-100 text-xl leading-none" title="Close (Esc)">&times;</button>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-h-0 bg-gray-100">
                                        <template x-if="current?.kind === 'pdf'">
                                            <iframe :src="current.url" class="w-full h-full bg-white" title="Document preview"></iframe>
                                        </template>
                                        <template x-if="current?.kind === 'image'">
                                            <div class="w-full h-full overflow-auto flex items-center justify-center p-4">
                                                <img :src="current.url" :alt="current.name" class="max-w-full max-h-full object-contain shadow">
                                            </div>
                                        </template>
                                        <template x-if="current?.kind === 'video'">
                                            <div class="w-full h-full flex items-center justify-center bg-black">
                                                <video :src="current.url" controls class="max-w-full max-h-full"></video>
                                            </div>
                                        </template>
                                        <template x-if="current?.kind === 'docx'">
                                            <div class="w-full h-full relative">
                                                <div x-show="loading" class="absolute inset-0 flex items-center justify-center text-sm text-gray-500">Loading Word document…</div>
                                                <div x-show="error" x-cloak class="m-4 text-sm text-amber-800 bg-amber-50 rounded-md p-3" x-text="error"></div>
                                                <div x-ref="docx" class="w-full h-full"></div>
                                            </div>
                                        </template>
                                        <template x-if="current && ! current.kind">
                                            <div class="w-full h-full flex flex-col items-center justify-center text-center p-6">
                                                <p class="text-sm text-gray-600">This file type can't be previewed in the browser.</p>
                                                <a :href="current.downloadUrl" class="mt-3 inline-flex px-4 py-2 bg-brand-600 rounded-lg text-sm font-medium text-white hover:bg-brand-700">Download to open it</a>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="{{ $cardClass }}">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-base font-semibold text-gray-900">Reference Attachments ({{ $document->referenceAttachments->count() }})</h3>
                            <div class="flex items-center gap-3">
                                @unless ($isAgencyUser)
                                <a href="{{ route('library.folders.index', ['document' => $document->id]) }}" class="text-sm text-brand-600 hover:underline">📚 Browse Library</a>
                                @endunless
                                @if ($isOwner)
                                    <button type="button" onclick="document.getElementById('upload-reference-form').classList.toggle('hidden')" class="text-sm text-brand-600 hover:underline">+ Attach reference file</button>
                                @endif
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mb-3">Source studies, certificates, or other supporting files kept alongside this document — separate from its own versions. Reuse a file another team already uploaded via the Library instead of re-uploading it.</p>

                        @if ($isOwner)
                            <form id="upload-reference-form" method="POST" action="{{ route('documents.references.store', $document) }}" enctype="multipart/form-data" class="hidden mb-4 p-4 bg-gray-50 rounded-lg space-y-3">
                                @csrf
                                <input type="text" name="title" placeholder="Reference title (optional, e.g. Vincent 2020 Final Study Report — defaults to the file name)" class="block w-full text-sm border-gray-300 rounded-lg">
                                <input type="text" name="category" placeholder="Category (optional, e.g. Study, Certificate, Legal Template)" class="block w-full text-sm border-gray-300 rounded-lg">
                                <x-file-dropzone name="file" required />
                                <x-primary-button>Attach</x-primary-button>
                            </form>
                        @endif

                        @forelse ($document->referenceAttachments as $reference)
                            <div class="flex items-center justify-between py-2.5 border-t border-gray-100 first:border-t-0 text-sm">
                                <div class="min-w-0">
                                    <span class="text-gray-900">{{ $reference->title }}</span>
                                    @if ($reference->category)
                                        <span class="text-[10px] px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded ml-1">{{ $reference->category }}</span>
                                    @endif
                                    <span class="text-xs text-gray-400 ml-1">{{ $reference->original_filename }} &middot; {{ $reference->humanFileSize() }} &middot; {{ $reference->uploader?->name }}</span>
                                </div>
                                <div class="flex items-center gap-3 shrink-0">
                                    <a href="{{ $reference->downloadUrl() }}" class="text-brand-600 hover:underline text-xs font-medium">Download</a>
                                    @if ($isOwner)
                                        <form method="POST" action="{{ route('documents.references.destroy', [$document, $reference]) }}" onsubmit="return confirm('Remove this reference file?');">
                                            @csrf @method('DELETE')
                                            <button class="text-xs text-gray-400 hover:text-red-600">&times;</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-400 py-2">No reference files attached yet.</p>
                        @endforelse
                    </div>

                    @if ($document->currentVersion?->isPdf())
                        <div class="{{ $cardClass }}">
                            <div class="flex items-center justify-between mb-1">
                                <h3 class="text-base font-semibold text-gray-900">Reference Links ({{ $document->currentVersion->pdfLinks->count() }})</h3>
                                @if ($isOwner)
                                    <form method="POST" action="{{ route('documents.pdf-links.extract', $document) }}">
                                        @csrf
                                        <button class="text-sm text-brand-600 hover:underline">🔗 Scan PDF for Links</button>
                                    </form>
                                @endif
                            </div>
                            <p class="text-xs text-gray-400 mb-3">Hyperlinks embedded directly in the PDF (not typed text) — connect each one to its actual reference file so a reader can jump straight to the source.</p>

                            @forelse ($document->currentVersion->pdfLinks as $link)
                                <div class="flex items-center justify-between gap-3 py-2.5 border-t border-gray-100 first:border-t-0 text-sm">
                                    <div class="min-w-0">
                                        <span class="text-xs text-gray-400 mr-1">p.{{ $link->page_number }}</span>
                                        <a href="{{ $link->uri }}" target="_blank" rel="noopener" class="text-gray-800 hover:text-brand-600 hover:underline break-all">{{ $link->targetLabel() }}</a>
                                        @if ($link->status === 'matched' && $link->matchedReference)
                                            <p class="text-xs text-emerald-700 mt-0.5">✓ Linked to: {{ $link->matchedReference->title }}</p>
                                        @elseif ($link->status === 'ignored')
                                            <p class="text-xs text-gray-400 mt-0.5">Not a reference</p>
                                        @endif
                                    </div>
                                    @if ($isOwner)
                                        <div class="flex items-center gap-2 shrink-0">
                                            @if ($link->status === 'matched')
                                                <form method="POST" action="{{ route('documents.pdf-links.unlink', [$document, $link]) }}">
                                                    @csrf
                                                    <button class="text-xs text-gray-400 hover:text-red-600">Unlink</button>
                                                </form>
                                            @else
                                                @if ($document->referenceAttachments->isNotEmpty())
                                                    <form method="POST" action="{{ route('documents.pdf-links.link', [$document, $link]) }}" class="flex items-center gap-1">
                                                        @csrf
                                                        <select name="reference_attachment_id" class="text-xs border-gray-300 rounded-lg py-1">
                                                            <option value="">Link to…</option>
                                                            @foreach ($document->referenceAttachments as $reference)
                                                                <option value="{{ $reference->id }}">{{ $reference->title }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button class="text-xs text-brand-600 hover:underline">Go</button>
                                                    </form>
                                                @endif
                                                @if ($link->status !== 'ignored')
                                                    <form method="POST" action="{{ route('documents.pdf-links.ignore', [$document, $link]) }}">
                                                        @csrf
                                                        <button class="text-xs text-gray-400 hover:text-gray-600">Ignore</button>
                                                    </form>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-gray-400 py-2">No links scanned yet — click "Scan PDF for Links" to check for embedded hyperlinks.</p>
                            @endforelse
                        </div>
                    @endif

                </div>

                <!-- Right: actions, workflow status, activity, AI tools, metadata -->
                <div class="space-y-6">
                    @if ($isAgencyUser)
                        @include('documents.partials.agency-action-bar')
                    @else

                    @if ($isPendingApprover && (! $user->canRecordDecisions() || $isOwner))
                        <div class="bg-white rounded-xl border border-gray-200 p-6 text-sm text-gray-600">
                            You're listed at <strong>{{ $myAssignment->stage->name }}</strong>, but makers can't be checkers — Brand Managers start, upload and assign tasks rather than approving or rejecting them.
                            @if ($canManageTasks) Use <em>Reassign</em> under Workflow Status to hand it to the right stakeholder. @endif
                        </div>
                    @elseif ($isPendingApprover)
                        <div class="bg-white rounded-xl border-2 border-brand-200 p-6 shadow-sm shadow-brand-100/50">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="w-2 h-2 rounded-full bg-accent-500 animate-pulse"></span>
                                <h3 class="text-base font-semibold text-gray-900">{{ $myStageIsOffline ? 'Record the offline sign-off' : ($myStageIsDraft ? 'Align the draft' : 'Your Decision Needed') }}</h3>
                            </div>
                            <p class="text-xs text-gray-500 mb-3">
                                {{ $myAssignment->stage->name }}
                                @if ($myAssignment->dueAt())
                                    · <span class="{{ $myAssignment->isOverdue() ? 'text-red-600 font-medium' : ($myAssignment->isDueSoon() ? 'text-amber-600 font-medium' : '') }}">due {{ $myAssignment->dueAt()->format('d M Y, H:i') }}</span>
                                @endif
                                @if ($myStageIsDraft) · check the Brand Manager's draft; send it back with changes until it's aligned. @endif
                            </p>
                            @if ($myStageIsOffline)
                                <p class="text-xs text-gray-600 bg-gray-50 rounded-md p-2 mb-3">The Chairperson reviews this offline. Upload the copy you received back from the Chairperson and record their outcome — approving it closes the workflow as <strong>Approved for Production</strong>.</p>
                            @endif
                            <form method="POST" action="{{ route('approvals.act', $instance) }}" class="space-y-3" x-data="{ decision: null }" enctype="multipart/form-data">
                                @csrf
                                <div class="grid gap-2 text-sm">
                                    <label class="flex items-center gap-2.5 border border-gray-200 rounded-lg px-3 py-2.5 cursor-pointer transition has-[:checked]:border-emerald-400 has-[:checked]:bg-emerald-50 has-[:checked]:ring-1 has-[:checked]:ring-emerald-300 hover:border-gray-300">
                                        <input type="radio" name="decision" value="approved" required class="text-emerald-600 focus:ring-emerald-500">
                                        <span>
                                            <span class="font-medium text-gray-800">{{ $myStageIsDraft ? 'Draft aligned' : ($myStageIsOffline ? 'Approved by the Chairperson' : 'Approved') }}</span>
                                            <span class="block text-xs text-gray-400">{{ $myStageIsDraft ? 'Back to the Brand Manager to commission the creative' : ($myStageIsFinal ? 'Closes the workflow' : 'Moves on to the next stage') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-center gap-2.5 border border-gray-200 rounded-lg px-3 py-2.5 cursor-pointer transition has-[:checked]:border-amber-400 has-[:checked]:bg-amber-50 has-[:checked]:ring-1 has-[:checked]:ring-amber-300 hover:border-gray-300">
                                        <input type="radio" name="decision" value="approved_with_changes" class="text-amber-600 focus:ring-amber-500">
                                        <span>
                                            <span class="font-medium text-gray-800">{{ $myStageIsDraft ? 'Needs changes' : 'Approved with Changes' }}</span>
                                            <span class="block text-xs text-gray-400">{{ $myStageIsDraft ? 'Back to the Brand Manager to revise, then back to you' : ($myStageIsFinal ? 'Back to the Brand Manager to make the changes, then it closes' : 'Moves on to the next stage; the Brand Manager sees your changes') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-center gap-2.5 border border-gray-200 rounded-lg px-3 py-2.5 cursor-pointer transition has-[:checked]:border-red-400 has-[:checked]:bg-red-50 has-[:checked]:ring-1 has-[:checked]:ring-red-300 hover:border-gray-300">
                                        <input type="radio" name="decision" value="not_approved" class="text-red-600 focus:ring-red-500">
                                        <span>
                                            <span class="font-medium text-gray-800">Not Approved</span>
                                            <span class="block text-xs text-gray-400">Back to the Brand Manager to revise — returns to {{ $myStageIsOffline ? 'this stage' : 'you' }} once resubmitted</span>
                                        </span>
                                    </label>
                                </div>
                                <textarea name="comments" rows="3" placeholder="Comments (required unless a clean Approve)" class="block w-full text-sm border-gray-300 rounded-lg"></textarea>
                                @if ($myStageIsOffline)
                                    <div>
                                        <label class="text-xs font-medium text-gray-700">Copy received from the Chairperson</label>
                                        <x-file-dropzone name="signed_copy" hint="Required for an approval — the signed or commented file." />
                                        <x-input-error :messages="$errors->get('signed_copy')" class="mt-1" />
                                    </div>
                                @endif

                                <x-input-error :messages="$errors->get('approval')" class="mt-1" />
                                @if ($requirePassword)
                                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                                        <p class="text-xs text-gray-600 mb-2">
                                            By entering your password and submitting, you are applying your electronic signature to this decision under 21 CFR Part 11 — it will be permanently recorded as <strong>{{ auth()->user()->name }}</strong>, signed at the time of submission.
                                        </p>
                                        <x-input-label for="signature-password" value="Password (to sign)" class="!text-xs" />
                                        <x-text-input type="password" id="signature-password" name="password" required autocomplete="current-password" class="mt-1 block w-full text-sm" placeholder="Re-enter your password to sign" />
                                        <x-input-error :messages="$errors->get('password')" class="mt-1" />
                                    </div>
                                @else
                                    <p class="text-[11px] text-gray-400">Recorded as <strong class="text-gray-600">{{ auth()->user()->name }}</strong> with the date, time and IP address.</p>
                                @endif

                                <button type="submit" class="w-full inline-flex items-center justify-center px-4 py-2.5 bg-brand-600 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 transition">
                                    {{ $myStageIsDraft ? 'Submit' : ($myStageIsOffline ? 'Upload & record sign-off' : 'Submit review') }}
                                </button>
                            </form>
                        </div>
                    @endif

                    @if ($isPendingClosure && $user->can('close', $document))
                        <div class="bg-white rounded-xl border-2 border-emerald-200 p-6">
                            <h3 class="text-base font-semibold text-gray-900 mb-1">Final approval &amp; closure</h3>
                            <p class="text-xs text-gray-500 mb-3">
                                @if ($closureDecision?->decision === 'approved_with_changes')
                                    The Chairperson approved with changes. Upload the corrected version if needed, then close the job — only you, as Brand Manager, can close it.
                                @else
                                    The Chairperson approved it. Give your final approval to close the job.
                                @endif
                                It then becomes <strong>Approved for Production</strong>, and you submit it for distribution.
                            </p>
                            @if ($closureDecision?->comments)
                                <p class="text-xs text-gray-600 bg-emerald-50 rounded-md p-2 mb-3"><strong>{{ $closureDecision->actor?->name }}:</strong> {{ $closureDecision->comments }}</p>
                            @endif
                            <form method="POST" action="{{ route('documents.close', $document) }}" enctype="multipart/form-data" class="space-y-3">
                                @csrf
                                @if ($closureDecision?->decision === 'approved_with_changes')
                                    <x-file-dropzone name="file" hint="New version with the Chairperson's changes (optional)." />
                                    <input type="text" name="change_notes" placeholder="What changed? (optional)" class="block w-full text-sm border-gray-300 rounded-lg">
                                @endif
                                <x-input-error :messages="$errors->get('closure')" class="mt-1" />
                                <x-primary-button class="w-full !justify-center !text-sm !normal-case !tracking-normal !py-2.5" onclick="return confirm('Give the final approval and close this job?')">Approve &amp; close</x-primary-button>
                            </form>
                        </div>
                    @endif

                    @if ($isOwner && $document->status === 'draft')
                        <div class="bg-white rounded-xl border-2 border-brand-200 p-6">
                            <h3 class="text-base font-semibold text-gray-900 mb-1">{{ $firstStageIsDraft ? 'Send for draft alignment' : 'Assign to stakeholders' }}</h3>
                            <p class="text-xs text-gray-400 mb-3">
                                @if (! $document->currentVersion)
                                    Upload the Word draft under Versions first, or assign the creative to the Content Agency / Design Team below.
                                @elseif ($firstStageIsDraft)
                                    Sends the draft to the Content Manager. It goes back and forth with you until it's aligned, then comes back to you to commission the creative.
                                @else
                                    Starts the workflow with the stakeholders you picked.
                                @endif
                            </p>
                            @if ($document->currentVersion)
                                <form method="POST" action="{{ route('documents.submit', $document) }}">
                                    @csrf
                                    <x-primary-button class="w-full !justify-center !text-sm !normal-case !tracking-normal !py-2.5">{{ $firstStageIsDraft ? 'Send to Content Manager' : 'Start workflow' }}</x-primary-button>
                                </form>
                            @endif
                        </div>
                    @endif

                    @if (($isOwner || $canManageTasks) && $isBackWithOwner)
                        <div id="revise" class="bg-white rounded-xl border-2 {{ $isDraftAligned ? 'border-violet-200' : 'border-amber-200' }} p-6" x-data="{ mode: 'upload' }">
                            <h3 class="text-base font-semibold text-gray-900 mb-1">{{ $isDraftAligned ? 'Draft aligned — commission the creative' : 'Revise & Resubmit' }}</h3>
                            @if ($lastDecision?->comments)
                                <p class="text-xs text-gray-600 bg-amber-50 rounded-md p-2 mb-3"><strong>{{ $lastDecision->actor?->name }}:</strong> {{ $lastDecision->comments }}</p>
                            @endif

                            @if ($openWorkTask)
                                <div class="text-sm text-gray-700 space-y-2">
                                    <p>With the {{ $openWorkTask->teamLabel() }}{{ $openWorkTask->assignee ? ' — ' . $openWorkTask->assignee->name : ' (not yet assigned)' }}{{ $openWorkTask->due_at ? ', due ' . $openWorkTask->due_at->format('d M, H:i') : '' }}.</p>
                                    <p class="text-xs text-gray-500">{{ $openWorkTask->resubmit_on_upload ? 'Their upload goes straight back into review.' : 'Their upload comes back to you to confirm before it goes for review.' }}</p>
                                    <form method="POST" action="{{ route('work-tasks.destroy', $openWorkTask) }}" onsubmit="return confirm('Withdraw this from the {{ $openWorkTask->teamLabel() }}?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">Withdraw from the {{ $openWorkTask->teamLabel() }}</button>
                                    </form>
                                </div>
                            @else
                                @if ($uploadToConfirm)
                                    <div class="text-xs text-emerald-800 bg-emerald-50 border border-emerald-200 rounded-md p-2 mb-3">
                                        <strong>v{{ $uploadToConfirm->version_no }}</strong> uploaded by {{ $uploadToConfirm->uploader?->name }} {{ $uploadToConfirm->created_at?->diffForHumans() }} — check it under Versions, then {{ $isDraftAligned ? 'start the review' : 'resubmit' }} below.
                                    </div>
                                @endif
                                <div class="flex flex-wrap gap-1 mb-3 text-xs">
                                    <button type="button" @click="mode = 'upload'" :class="mode === 'upload' ? 'bg-amber-100 text-amber-800' : 'text-gray-500 hover:bg-gray-50'" class="px-2.5 py-1 rounded-md font-medium">{{ $isDraftAligned ? 'Start review' : 'Upload it myself' }}</button>
                                    <button type="button" @click="mode = 'agency'" :class="mode === 'agency' ? 'bg-amber-100 text-amber-800' : 'text-gray-500 hover:bg-gray-50'" class="px-2.5 py-1 rounded-md font-medium">Content Agency</button>
                                    <button type="button" @click="mode = 'design'" :class="mode === 'design' ? 'bg-amber-100 text-amber-800' : 'text-gray-500 hover:bg-gray-50'" class="px-2.5 py-1 rounded-md font-medium">Internal Design Team</button>
                                </div>
                                <form x-show="mode === 'upload'" method="POST" action="{{ route('documents.submit', $document) }}" enctype="multipart/form-data" class="space-y-3">
                                    @csrf
                                    <x-file-dropzone name="file" />
                                    <input type="text" name="change_notes" placeholder="{{ $isDraftAligned ? 'Notes (optional)' : 'What changed?' }}" class="block w-full text-sm border-gray-300 rounded-lg">
                                    <x-primary-button class="w-full !justify-center !text-sm !normal-case !tracking-normal !py-2.5">{{ $isDraftAligned ? 'Start review' . ($instance?->resumeAtStage ? ' — ' . $instance->resumeAtStage->name : '') : 'Resubmit to ' . ($lastDecision?->stage?->name ?? 'review') }}</x-primary-button>
                                    <p class="text-[11px] text-gray-400">{{ $isDraftAligned ? 'Attach the creative here, or leave it empty to send the current version on.' : 'Already uploaded the revision under Versions? Leave the file empty and resubmit.' }}</p>
                                </form>
                                @foreach (['agency' => 'Content Agency', 'design' => 'Internal Design Team'] as $team => $teamName)
                                    <form x-show="mode === '{{ $team }}'" x-cloak method="POST" action="{{ route('documents.work-tasks.store', $document) }}" class="space-y-3">
                                        @csrf
                                        <input type="hidden" name="team" value="{{ $team }}">
                                        <textarea name="instructions" rows="3" class="block w-full text-sm border-gray-300 rounded-lg" placeholder="{{ $isDraftAligned ? 'Brief for the creative (the aligned draft is attached as the current version)' : 'What should be changed?' }}">{{ $isDraftAligned ? '' : $lastDecision?->comments }}</textarea>
                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="text-xs text-gray-500">Due date</label>
                                                <input type="date" name="due_date" min="{{ now()->toDateString() }}" value="{{ now()->addHours(config('promomats.due_dates.default_stage_hours', 48))->toDateString() }}" class="mt-0.5 block w-full text-sm border-gray-300 rounded-lg">
                                            </div>
                                            @if ($team === 'agency' && $agencies->isNotEmpty())
                                                <div x-data="{ agencyId: '{{ $agencies->count() === 1 ? $agencies->first()->id : '' }}' }" class="contents">
                                                    <div>
                                                        <label class="text-xs text-gray-500">Content agency</label>
                                                        <select name="content_agency_id" x-model="agencyId" required class="mt-0.5 block w-full text-sm border-gray-300 rounded-lg">
                                                            <option value="">Select…</option>
                                                            @foreach ($agencies as $agency)
                                                                <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="col-span-2">
                                                        <label class="text-xs text-gray-500">Person at the agency</label>
                                                        <select name="assign_to" class="mt-0.5 block w-full text-sm border-gray-300 rounded-lg">
                                                            <option value="">Anyone at the agency</option>
                                                            @foreach ($agencies as $agency)
                                                                @foreach ($agency->users as $member)
                                                                    <option value="{{ $member->id }}" x-show="agencyId === '{{ $agency->id }}'">{{ $member->name }}</option>
                                                                @endforeach
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>
                                            @else
                                            <div>
                                                <label class="text-xs text-gray-500">Person (optional)</label>
                                                <select name="assign_to" class="mt-0.5 block w-full text-sm border-gray-300 rounded-lg">
                                                    <option value="">Whole {{ $teamName }}</option>
                                                    @foreach ($teamMembers[$team] as $member)
                                                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            @endif
                                        </div>
                                        <x-input-error :messages="$errors->get('content_agency_id')" class="mt-1" />
                                        @if ($team === 'design' && $isParked)
                                            <label class="flex items-center gap-2 text-xs text-gray-600">
                                                <input type="hidden" name="resubmit_on_upload" value="0">
                                                <input type="checkbox" name="resubmit_on_upload" value="1" class="rounded border-gray-300 text-brand-600">
                                                Skip my confirmation — send it back into review as soon as it's uploaded
                                            </label>
                                        @else
                                            <p class="text-[11px] text-gray-400">Their upload comes back to you to confirm before it goes for review.</p>
                                        @endif
                                        @if ($teamMembers[$team]->isEmpty() && ! ($team === 'agency' && $agencies->isNotEmpty()))
                                            <p class="text-[11px] text-amber-700">No one has the {{ $teamName }} role yet — ask an admin to assign it.</p>
                                        @endif
                                        <x-primary-button class="w-full !justify-center !text-sm !normal-case !tracking-normal !py-2.5">Send to {{ $teamName }}</x-primary-button>
                                    </form>
                                @endforeach
                            @endif
                        </div>
                    @endif

                    {{-- Placeholder with no file yet: the Brand Manager may hand the brief straight to a team. --}}
                    @if (($isOwner || $canManageTasks) && $document->status === 'draft' && $document->is_placeholder && ! $openWorkTask)
                        <div class="bg-white rounded-xl border-2 border-violet-200 p-6" x-data="{ team: 'agency' }">
                            <h3 class="text-base font-semibold text-gray-900 mb-1">Assign the creative</h3>
                            <p class="text-xs text-gray-400 mb-3">Skip draft alignment and send the brief straight to a team. Their upload comes back to you.</p>
                            <form method="POST" action="{{ route('documents.placeholder.assign', $document) }}" class="space-y-3">
                                @csrf
                                <select name="team" x-model="team" class="block w-full text-sm border-gray-300 rounded-lg">
                                    <option value="agency">Content Agency</option>
                                    <option value="design">Internal Design Team</option>
                                </select>
                                @if ($agencies->isNotEmpty())
                                    <div x-show="team === 'agency'" x-data="{ agencyId: '{{ $agencies->count() === 1 ? $agencies->first()->id : '' }}' }" class="grid grid-cols-2 gap-2">
                                        <select name="content_agency_id" x-model="agencyId" :required="team === 'agency'" :disabled="team !== 'agency'" class="block w-full text-sm border-gray-300 rounded-lg">
                                            <option value="">Content agency…</option>
                                            @foreach ($agencies as $agency)
                                                <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                                            @endforeach
                                        </select>
                                        <select name="assign_to" :disabled="team !== 'agency'" class="block w-full text-sm border-gray-300 rounded-lg">
                                            <option value="">Anyone at the agency</option>
                                            @foreach ($agencies as $agency)
                                                @foreach ($agency->users as $member)
                                                    <option value="{{ $member->id }}" x-show="agencyId === '{{ $agency->id }}'">{{ $member->name }}</option>
                                                @endforeach
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                                <textarea name="instructions" rows="3" class="block w-full text-sm border-gray-300 rounded-lg" placeholder="Brief">{{ $document->description }}</textarea>
                                <x-primary-button class="w-full !justify-center !text-sm !normal-case !tracking-normal !py-2.5">Send brief</x-primary-button>
                            </form>
                        </div>
                    @endif

                    @if ($openWorkTask && ($user->inTeam($openWorkTask->team) || $openWorkTask->assigned_to === $user->id) && ! $canManageTasks)
                        <div class="bg-white rounded-xl border-2 border-violet-200 p-6">
                            <h3 class="text-base font-semibold text-gray-900 mb-1">{{ $openWorkTask->teamLabel() }}: {{ $openWorkTask->typeLabel() }}</h3>
                            <p class="text-xs text-gray-500 mb-2">From {{ $openWorkTask->requester?->name }}{{ $openWorkTask->due_at ? ' · due ' . $openWorkTask->due_at->format('d M, H:i') : '' }} · {{ $openWorkTask->assignee ? 'assigned to ' . $openWorkTask->assignee->name : 'unassigned' }}</p>
                            @if ($openWorkTask->instructions)
                                <p class="text-sm text-gray-700 whitespace-pre-line mb-3">{{ $openWorkTask->instructions }}</p>
                            @endif
                            @include('workflow.partials.work-task-rows', ['tasks' => collect([$openWorkTask])])
                        </div>
                    @endif

                    <div class="{{ $cardClass }}">
                        <h3 class="text-base font-semibold text-gray-900 mb-3">Workflow Status</h3>

                        @if ($instance)
                            <p class="text-xs text-gray-400 mb-1.5">
                                {{ $openRuns->count() > 1 ? 'Currently in parallel review — every track below must clear before this moves on' : 'Current stage' }}
                            </p>

                            @forelse ($openRuns as $run)
                                <div class="mb-3 last:mb-0">
                                    <p class="font-medium text-gray-900 mb-1.5">{{ $run->stage->name }}</p>
                                    <ul class="space-y-1.5 text-sm">
                                        @forelse ($instance->pendingAssignees->where('workflow_stage_id', $run->workflow_stage_id) as $assignee)
                                            <li x-data="{ edit: false }">
                                                <div class="flex items-center gap-2">
                                                    <x-avatar :name="$assignee->user?->name" size="xs" />
                                                    <span class="text-gray-800">{{ $assignee->user?->name }}</span>
                                                    @if ($assignee->isOverdue())
                                                        <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-red-100 text-red-700 font-medium">overdue</span>
                                                    @elseif ($assignee->isDueSoon())
                                                        <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 font-medium">due soon</span>
                                                    @endif
                                                    @if ($canManageTasks)
                                                        <button type="button" @click="edit = ! edit" class="ml-auto text-xs text-brand-600 hover:underline">Reassign / due</button>
                                                    @endif
                                                </div>
                                                <p class="text-[11px] text-gray-400 ml-7">
                                                    Due {{ $assignee->dueAt()?->format('d M Y, H:i') ?? '—' }}
                                                    @if ($assignee->reassigned_from_id) · reassigned by {{ $assignee->reassignedBy?->name }}{{ $assignee->reassign_reason ? ' (' . $assignee->reassign_reason . ')' : '' }} @endif
                                                </p>
                                                @if ($canManageTasks)
                                                    <div x-show="edit" x-cloak class="mt-2 ml-7 p-2.5 bg-gray-50 rounded-lg space-y-2">
                                                        <form method="POST" action="{{ route('documents.tasks.reassign', [$document, $assignee]) }}" class="space-y-1.5">
                                                            @csrf
                                                            <x-search-select size="sm" placeholder="Reassign to… (search)">
                                                                <select name="user_id" required>
                                                                    <option value="">Reassign to…</option>
                                                                    @foreach ($reassignOptions as $option)
                                                                        @if ($option->id !== $assignee->user_id)
                                                                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                                                                        @endif
                                                                    @endforeach
                                                                </select>
                                                            </x-search-select>
                                                            <input type="text" name="reason" placeholder="Reason (e.g. on leave)" class="block w-full text-xs border-gray-300 rounded-md">
                                                            <button class="text-xs font-medium text-white bg-brand-600 rounded-md px-2.5 py-1">Reassign</button>
                                                        </form>
                                                        <form method="POST" action="{{ route('documents.tasks.due', [$document, $assignee]) }}" class="flex items-center gap-1.5">
                                                            @csrf
                                                            <input type="datetime-local" name="due_at" required value="{{ $assignee->dueAt()?->format('Y-m-d\TH:i') }}" class="text-xs border-gray-300 rounded-md py-1">
                                                            <button class="text-xs text-brand-700 border border-brand-200 rounded-md px-2 py-1">Set due</button>
                                                        </form>
                                                    </div>
                                                @endif
                                            </li>
                                        @empty
                                            <li class="text-gray-400">None</li>
                                        @endforelse
                                    </ul>
                                </div>
                            @empty
                                @if ($isBackWithOwner)
                                    <p class="text-sm text-amber-700">With {{ $openWorkTask ? 'the ' . $openWorkTask->teamLabel() : $document->owner?->name . ' (Brand Manager)' }} {{ $isDraftAligned ? '— draft aligned, creative next' : 'for revision' }}.</p>
                                @else
                                    <p class="text-sm text-gray-400">No stage currently open.</p>
                                @endif
                            @endforelse
                            <x-input-error :messages="$errors->get('reassign')" class="mt-2" />
                        @elseif ($isPendingClosure)
                            <p class="text-sm text-emerald-700">Reviews complete — with {{ $document->owner?->name }} (Brand Manager) for final approval and closure.</p>
                        @else
                            <p class="text-sm text-gray-400">Not yet submitted into a workflow.</p>
                        @endif

                        @php $matrix = $stageSchedule->where('state', '!=', 'skipped'); @endphp
                        @if ($matrix->isNotEmpty())
                            <div class="mt-4 pt-4 border-t border-gray-100">
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Approval matrix &amp; due dates</p>
                                    @if ($document->due_date)
                                        <span class="text-xs text-gray-500">Overall due <strong class="text-gray-800">{{ $document->due_date->format('d M Y') }}</strong></span>
                                    @endif
                                </div>
                                <ul class="space-y-2 text-sm">
                                    @foreach ($matrix as $row)
                                        <li x-data="{ edit: false }">
                                            <div class="flex items-start gap-2">
                                                <span @class([
                                                    'mt-0.5 w-2 h-2 rounded-full shrink-0',
                                                    'bg-brand-600' => $row['state'] === 'done',
                                                    'bg-accent-500' => $row['state'] === 'open',
                                                    'bg-gray-300' => $row['state'] === 'upcoming',
                                                ])></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-gray-800 leading-tight">{{ $row['stage']->name }}
                                                        @if ($row['stage']->parallel_group)<span class="text-[9px] uppercase tracking-wide text-brand-600 ml-1">parallel</span>@endif
                                                    </p>
                                                    <p class="text-[11px] text-gray-400">
                                                        {{ collect($row['assignee_ids'])->map(fn ($id) => $scheduleUserNames[$id] ?? null)->filter()->join(', ') ?: ($row['state'] === 'done' ? 'Done' : 'Usual reviewers') }}
                                                    </p>
                                                </div>
                                                <div class="text-right shrink-0">
                                                    @if ($row['state'] === 'done')
                                                        <span class="text-[11px] text-brand-700">✓ done</span>
                                                    @else
                                                        <span class="text-[11px] {{ $row['due_at']?->isPast() ? 'text-red-600 font-medium' : 'text-gray-600' }}">{{ $row['state'] === 'upcoming' ? 'planned ' : 'due ' }}{{ $row['due_at']?->format('d M, H:i') ?? '—' }}</span>
                                                        @if ($row['overridden'])<span class="block text-[9px] uppercase tracking-wide text-amber-600">set by BM</span>@endif
                                                        @if ($canManageTasks)
                                                            <button type="button" @click="edit = ! edit" class="block ml-auto text-[11px] text-brand-600 hover:underline">Change</button>
                                                        @endif
                                                    @endif
                                                </div>
                                            </div>
                                            @if ($canManageTasks && $row['state'] !== 'done')
                                                <form x-show="edit" x-cloak method="POST" action="{{ route('documents.stages.due', [$document, $row['stage']]) }}" class="mt-1.5 ml-4 flex items-center gap-1.5">
                                                    @csrf
                                                    <input type="datetime-local" name="due_at" required min="{{ now()->format('Y-m-d\TH:i') }}" value="{{ $row['due_at']?->format('Y-m-d\TH:i') }}" class="text-xs border-gray-300 rounded-md py-1">
                                                    <button class="text-xs font-medium text-white bg-brand-600 rounded-md px-2.5 py-1">Save</button>
                                                </form>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="mt-2 text-[11px] text-gray-400">All times IST. Changing a due date notifies the stage's stakeholders.</p>
                                <x-input-error :messages="$errors->get('due_at')" class="mt-1" />
                            </div>
                        @endif
                    </div>

                    <div class="bg-white rounded-xl border border-gray-200/80 overflow-hidden" x-data="{ tab: 'history' }" id="activity-panel">
                        <div class="flex items-center border-b border-gray-100 px-6 pt-5 pb-1">
                            <h3 class="text-base font-semibold text-gray-900 mr-auto">Activity</h3>
                            <a href="{{ route('documents.history-report', $document) }}" target="_blank" class="text-xs text-brand-600 hover:underline">📄 Full History Report</a>
                        </div>
                        <div class="flex px-6 border-b border-gray-100 gap-1">
                            <button type="button" @click="tab = 'history'"
                                    :class="tab === 'history' ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-400 hover:text-gray-700'"
                                    class="text-xs font-medium px-1 py-2.5 mr-4 border-b-2 -mb-px transition">
                                Review Actions ({{ $document->approvalActions->count() }})
                            </button>
                            <button type="button" @click="tab = 'comments'"
                                    :class="tab === 'comments' ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-400 hover:text-gray-700'"
                                    class="text-xs font-medium px-1 py-2.5 mr-4 border-b-2 -mb-px transition">
                                Comments ({{ $commentCount }})
                            </button>
                            @php $allPdfEdits = $document->versions->flatMap->pdfEdits->sortByDesc('created_at'); @endphp
                            <button type="button" @click="tab = 'edits'"
                                    :class="tab === 'edits' ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-400 hover:text-gray-700'"
                                    class="text-xs font-medium px-1 py-2.5 border-b-2 -mb-px transition">
                                Content Edits ({{ $allPdfEdits->count() }})
                            </button>
                        </div>

                        <div x-show="tab === 'history'" class="p-6 max-h-[32rem] overflow-y-auto">
                            @forelse ($document->approvalActions as $action)
                                <div class="py-3 border-b last:border-b-0 border-gray-100 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-gray-900 flex items-center gap-1.5"><x-avatar :name="$action->actor?->name" size="xs" />{{ $action->actor?->name }}</span>
                                        <span class="text-gray-400 text-xs shrink-0">{{ $action->acted_at?->diffForHumans() }}</span>
                                    </div>
                                    <div class="text-gray-600 mt-1">{{ $action->decisionLabel() }} at <em>{{ $action->stage?->name }}</em></div>
                                    @if ($action->signed_name)
                                        <div class="mt-1 text-xs text-gray-500 flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                            Electronically signed by <span class="font-medium text-gray-700">{{ $action->signed_name }}</span> — 21 CFR Part 11
                                        </div>
                                    @endif
                                    @if ($action->comments)
                                        <div class="mt-1.5 text-gray-700 bg-gray-50 rounded-lg p-2.5">{{ $action->comments }}</div>
                                    @endif
                                    @if ($isOwner && $aiAvailable && $action->decision !== 'approved')
                                        <form method="POST" action="{{ route('documents.ai.suggest-revision', [$document, $action]) }}" class="mt-1.5">
                                            @csrf
                                            <button class="text-xs text-brand-600 hover:underline">🤖 Get AI revision suggestion for this feedback</button>
                                        </form>
                                        @if (session('ai_revision_result') && $latestRevisionInsight?->id === session('ai_revision_result'))
                                            <div class="mt-2 bg-brand-50 border border-brand-100 rounded-lg p-3 text-xs text-brand-900 whitespace-pre-line">
                                                <p class="font-semibold mb-1">🤖 AI suggestion (draft only — a human must review and rewrite as needed):</p>
                                                {{ $latestRevisionInsight->output }}
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-gray-400">No approval actions yet.</p>
                            @endforelse
                        </div>

                        <div x-show="tab === 'comments'" class="p-6 max-h-[32rem] overflow-y-auto" id="comments">
                            @if ($commentsLocked)
                                <p class="mb-5 text-xs text-gray-500">🔒 Commenting is locked — you've submitted your review.</p>
                            @else
                                <form method="POST" action="{{ route('documents.comments.store', $document) }}" class="mb-5 space-y-2">
                                    @csrf
                                    <textarea name="body" rows="2" required placeholder="{{ $commentsAreDraft ? 'Add a draft comment (visible to others once you submit your review)…' : 'Add a comment…' }}" class="block w-full text-sm border-gray-300 rounded-lg">{{ old('body') }}</textarea>
                                    <x-primary-button>{{ $commentsAreDraft ? 'Save draft comment' : 'Post Comment' }}</x-primary-button>
                                </form>
                            @endif

                            @include('documents.partials.comments-by-version')
                        </div>

                        <div x-show="tab === 'edits'" class="p-6 max-h-[32rem] overflow-y-auto">
                            <p class="text-xs text-gray-400 mb-3">Every real content edit (added text or a redacted region) made directly in this PDF, across every version — attributed and timestamped, baked as a visible highlight into the saved file itself.</p>
                            @forelse ($allPdfEdits as $edit)
                                <div class="py-3 border-b last:border-b-0 border-gray-100 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-gray-900">{{ $edit->actor?->name }}</span>
                                        <span class="text-gray-400 text-xs shrink-0">{{ $edit->created_at->diffForHumans() }}</span>
                                    </div>
                                    <div class="text-gray-600 mt-1">
                                        <span @class([
                                            'inline-block w-2 h-2 rounded-full mr-1',
                                            'bg-brand-500' => $edit->edit_type === 'add_text',
                                            'bg-red-500' => $edit->edit_type === 'redact',
                                        ])></span>
                                        {{ $edit->typeLabel() }} on page {{ $edit->page_number }}
                                        <span class="text-gray-400">(v{{ $edit->documentVersion?->version_no }})</span>
                                    </div>
                                    @if ($edit->content)
                                        <div class="mt-1.5 text-gray-700 bg-gray-50 rounded-lg p-2.5">{{ $edit->content }}</div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-gray-400">No content edits yet.</p>
                            @endforelse
                        </div>
                    </div>

                    @if ($isOwner && $aiAvailable)
                        <div class="bg-gradient-to-br from-brand-50/60 to-white rounded-xl border border-brand-100 p-6">
                            <h3 class="text-base font-semibold text-gray-900 mb-3 flex items-center gap-1.5">🤖 AI Tools</h3>
                            <div class="flex flex-col gap-2">
                                <form method="POST" action="{{ route('documents.ai.compliance-check', $document) }}">
                                    @csrf
                                    <button class="w-full text-left text-sm px-3 py-2.5 bg-white border border-gray-200 rounded-lg hover:border-brand-300 hover:bg-brand-50 transition">
                                        Run AI Compliance Pre-check
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('documents.ai.suggest-claims', $document) }}">
                                    @csrf
                                    <button class="w-full text-left text-sm px-3 py-2.5 bg-white border border-gray-200 rounded-lg hover:border-brand-300 hover:bg-brand-50 transition">
                                        AI Suggest Claims
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('documents.ai.extract-claims', $document) }}">
                                    @csrf
                                    <button class="w-full text-left text-sm px-3 py-2.5 bg-white border border-gray-200 rounded-lg hover:border-brand-300 hover:bg-brand-50 transition">
                                        Extract New Claim Candidates
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('documents.ai.suggest-references', $document) }}">
                                    @csrf
                                    <button class="w-full text-left text-sm px-3 py-2.5 bg-white border border-gray-200 rounded-lg hover:border-brand-300 hover:bg-brand-50 transition">
                                        AI Suggest References
                                    </button>
                                </form>
                            </div>
                            <p class="text-xs text-gray-500 mt-3">Extraction proposes brand-new claims for the library (sent to Admin review) — separate from "Suggest Claims," which only recommends claims already approved. "Suggest References" matches this document against reference files already attached elsewhere in the system.</p>

                            @if ($latestComplianceInsight && session('ai_compliance_result') === $latestComplianceInsight->id)
                                @php $findings = json_decode($latestComplianceInsight->output, true) ?: []; @endphp
                                <div class="mt-4 pt-4 border-t border-brand-100">
                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-xs font-semibold text-gray-500 uppercase">Compliance Pre-check</p>
                                        <span @class([
                                            'text-xs px-2 py-0.5 rounded-full font-medium',
                                            'bg-red-100 text-red-700' => $latestComplianceInsight->risk_level === 'high',
                                            'bg-amber-100 text-amber-700' => $latestComplianceInsight->risk_level === 'medium',
                                            'bg-emerald-100 text-emerald-700' => $latestComplianceInsight->risk_level === 'low',
                                        ])>{{ ucfirst($latestComplianceInsight->risk_level) }} risk</span>
                                    </div>
                                    @forelse ($findings as $finding)
                                        <div class="text-xs py-1.5 border-b border-brand-50 last:border-b-0">
                                            <span @class([
                                                'inline-block w-1.5 h-1.5 rounded-full mr-1',
                                                'bg-red-500' => ($finding['severity'] ?? '') === 'high',
                                                'bg-amber-500' => ($finding['severity'] ?? '') === 'medium',
                                                'bg-gray-400' => ($finding['severity'] ?? '') === 'low',
                                            ])></span>
                                            <span class="text-gray-800">{{ $finding['issue'] ?? '' }}</span>
                                            @if (! empty($finding['suggestion']))
                                                <p class="text-gray-500 ml-2.5">→ {{ $finding['suggestion'] }}</p>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-xs text-emerald-700">No issues flagged.</p>
                                    @endforelse
                                </div>
                            @endif

                            @if (session('ai_claim_suggestions'))
                                <div class="mt-4 pt-4 border-t border-brand-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase mb-2">AI Suggested Claims</p>
                                    @forelse (session('ai_claim_suggestions') as $suggestion)
                                        <div class="flex items-center justify-between gap-2 py-1 text-xs">
                                            <span class="text-gray-800">{{ $suggestion['match_text'] }}</span>
                                            <form method="POST" action="{{ route('documents.claims.store', $document) }}">
                                                @csrf
                                                <input type="hidden" name="claim_id" value="{{ $suggestion['id'] }}">
                                                <button class="text-brand-600 hover:underline whitespace-nowrap">+ Insert</button>
                                            </form>
                                        </div>
                                    @empty
                                        <p class="text-xs text-gray-500">The AI didn't find any additional relevant claims.</p>
                                    @endforelse
                                </div>
                            @endif

                            @if (session('ai_reference_suggestions'))
                                <div class="mt-4 pt-4 border-t border-brand-100">
                                    <p class="text-xs font-semibold text-gray-500 uppercase mb-2">AI Suggested References</p>
                                    @forelse (session('ai_reference_suggestions') as $suggestion)
                                        <div class="flex items-center justify-between gap-2 py-1 text-xs">
                                            <span class="text-gray-800">{{ $suggestion['title'] }} <span class="text-gray-400">({{ $suggestion['original_filename'] }})</span></span>
                                            <form method="POST" action="{{ route('documents.references.attach-existing', $document) }}">
                                                @csrf
                                                <input type="hidden" name="reference_attachment_id" value="{{ $suggestion['id'] }}">
                                                <button class="text-brand-600 hover:underline whitespace-nowrap">+ Attach</button>
                                            </form>
                                        </div>
                                    @empty
                                        <p class="text-xs text-gray-500">The AI didn't find any relevant reference files elsewhere in the system.</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="{{ $cardClass }}">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-base font-semibold text-gray-900">Claims Used ({{ $document->claims->count() }})</h3>
                            @if ($isOwner && ($availableClaims->isNotEmpty() || $availableModules->isNotEmpty()))
                                <button type="button" onclick="document.getElementById('insert-claim-form').classList.toggle('hidden')" class="text-xs text-brand-600 hover:underline">+ Insert</button>
                            @endif
                        </div>

                        @if ($isOwner)
                            <form id="insert-claim-form" method="POST" action="{{ route('documents.claims.store', $document) }}" class="hidden mb-3 p-3 bg-gray-50 rounded-lg space-y-2 text-sm">
                                @csrf
                                @if ($availableClaims->isNotEmpty())
                                    <select name="claim_id" class="w-full text-xs border-gray-300 rounded-lg">
                                        <option value="">— Insert a single claim —</option>
                                        @foreach ($availableClaims as $c)
                                            <option value="{{ $c->id }}">{{ $c->match_text }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                @if ($availableModules->isNotEmpty())
                                    <select name="content_module_id" class="w-full text-xs border-gray-300 rounded-lg">
                                        <option value="">— …or insert a whole content module —</option>
                                        @foreach ($availableModules as $m)
                                            <option value="{{ $m->id }}">{{ $m->name }} ({{ $m->claims->count() }} claims)</option>
                                        @endforeach
                                    </select>
                                @endif
                                <x-primary-button type="submit">Insert</x-primary-button>
                            </form>
                        @endif

                        @forelse ($document->claims as $claim)
                            <div class="py-2.5 border-t border-gray-100 first:border-t-0 text-sm">
                                <div class="flex items-start justify-between gap-2">
                                    <span class="text-gray-800">{{ $claim->match_text }}</span>
                                    @if ($isOwner)
                                        <form method="POST" action="{{ route('documents.claims.destroy', [$document, $claim]) }}" onsubmit="return confirm('Remove this claim from the document?');">
                                            @csrf @method('DELETE')
                                            <button class="text-xs text-gray-400 hover:text-red-600 shrink-0">&times;</button>
                                        </form>
                                    @endif
                                </div>
                                @if ($claim->references->isNotEmpty())
                                    <p class="text-xs text-gray-400 mt-0.5">Ref: {{ $claim->references->first()->title }}</p>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-gray-400">No claims inserted yet.</p>
                        @endforelse

                        @if ($isOwner && $suggestedClaims->isNotEmpty())
                            <div class="mt-3 pt-3 border-t border-dashed border-gray-200">
                                <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Suggested Claims</p>
                                @foreach ($suggestedClaims as $suggestion)
                                    <div class="flex items-center justify-between gap-2 py-1 text-sm">
                                        <span class="text-gray-700">{{ $suggestion->match_text }}</span>
                                        <form method="POST" action="{{ route('documents.claims.store', $document) }}">
                                            @csrf
                                            <input type="hidden" name="claim_id" value="{{ $suggestion->id }}">
                                            <button class="text-xs text-brand-600 hover:underline whitespace-nowrap">+ Insert</button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="{{ $cardClass }}">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-base font-semibold text-gray-900">👁 Observers ({{ $document->watchers->count() }})</h3>
                            @if ($isOwner || auth()->user()->can('access-admin'))
                                <button type="button" onclick="document.getElementById('add-observer-form').classList.toggle('hidden')" class="text-xs text-brand-600 hover:underline">+ Add</button>
                            @endif
                        </div>
                        <p class="text-xs text-gray-400 mb-3">Read-only stakeholders — not approvers — who get notified of every status change and can track this document's progress.</p>

                        @if ($isOwner || auth()->user()->can('access-admin'))
                            <form id="add-observer-form" method="POST" action="{{ route('documents.observers.store', $document) }}" class="hidden mb-3 p-3 bg-gray-50 rounded-lg flex items-center gap-2">
                                @csrf
                                <x-search-select class="flex-1" placeholder="Search a user…">
                                    <select name="user_id" required>
                                        <option value="">Select a user…</option>
                                        @foreach ($observableUsers as $observable)
                                            <option value="{{ $observable->id }}">{{ $observable->name }}</option>
                                        @endforeach
                                    </select>
                                </x-search-select>
                                <x-primary-button type="submit">Add</x-primary-button>
                            </form>
                        @endif

                        @forelse ($document->watchers as $watcher)
                            <div class="flex items-center justify-between py-1.5 text-sm">
                                <span class="text-gray-800 flex items-center gap-1.5"><x-avatar :name="$watcher->name" size="xs" />{{ $watcher->name }}</span>
                                @if ($isOwner || auth()->user()->can('access-admin'))
                                    <form method="POST" action="{{ route('documents.observers.destroy', [$document, $watcher]) }}" onsubmit="return confirm('Remove this observer?');">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-gray-400 hover:text-red-600">&times;</button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-gray-400">No observers assigned.</p>
                        @endforelse
                    </div>

                    @if (($isOwner || auth()->user()->can('access-admin')) && $document->isOnColdStorage())
                        <div class="{{ $cardClass }}">
                            <h3 class="text-base font-semibold text-gray-900 mb-3">❄️ Cold Storage</h3>
                            @if ($latestRetrievalRequest && in_array($latestRetrievalRequest->status, ['pending', 'in_progress']))
                                <p class="text-sm text-gray-600">
                                    Retrieval requested {{ $latestRetrievalRequest->requested_at->diffForHumans() }} by {{ $latestRetrievalRequest->requestedBy?->name }}.
                                </p>
                                <p class="text-sm mt-1.5">
                                    <span @class([
                                        'px-2 py-0.5 rounded-full text-xs font-medium',
                                        'bg-gray-100 text-gray-600' => $latestRetrievalRequest->status === 'pending',
                                        'bg-amber-100 text-amber-700' => $latestRetrievalRequest->status === 'in_progress',
                                    ])>{{ $latestRetrievalRequest->statusLabel() }}</span>
                                    @if ($latestRetrievalRequest->isOverdue())
                                        <span class="text-red-600 text-xs font-medium ml-1">🚩 SLA overdue</span>
                                    @else
                                        <span class="text-gray-400 text-xs ml-1">SLA due {{ $latestRetrievalRequest->sla_due_at->diffForHumans() }}</span>
                                    @endif
                                </p>
                            @elseif ($latestRetrievalRequest && $latestRetrievalRequest->status === 'failed')
                                <p class="text-sm text-red-700 mb-2">The last retrieval attempt failed: {{ $latestRetrievalRequest->notes }}</p>
                                <form method="POST" action="{{ route('documents.retrieval.store', $document) }}">
                                    @csrf
                                    <x-primary-button>Request Retrieval Again</x-primary-button>
                                </form>
                            @else
                                <p class="text-sm text-gray-500 mb-3">This document's files have been moved to cold storage. Request retrieval to bring them back — standard SLA is {{ \App\Models\RetrievalRequest::SLA_HOURS }} hours.</p>
                                <form method="POST" action="{{ route('documents.retrieval.store', $document) }}">
                                    @csrf
                                    <x-primary-button>Request Retrieval</x-primary-button>
                                </form>
                            @endif
                        </div>
                    @endif

                    @if ($document->status === 'approved_for_distribution' && ($pendingDistribution = $document->distributionRecords->firstWhere('status', 'pending')))
                        <div class="{{ $cardClass }}">
                            <h3 class="text-base font-semibold text-gray-900 mb-3">Distribution</h3>
                            @if ($isOwner || auth()->user()->can('access-admin'))
                                <p class="text-sm text-gray-500 mb-3">Approved for distribution. Confirm once it has actually gone out.</p>
                                <form method="POST" action="{{ route('documents.distribution.confirm', $document) }}" class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div>
                                        <x-input-label for="distribution_channel" value="Channel" />
                                        <x-text-input id="distribution_channel" name="distribution_channel" class="mt-1 block w-full text-sm" placeholder="e.g. Field force, Email, Print" required />
                                    </div>
                                    <div>
                                        <x-input-label for="distribution_date" value="Date" />
                                        <x-text-input type="date" id="distribution_date" name="distribution_date" class="mt-1 block w-full text-sm" :value="now()->toDateString()" required />
                                    </div>
                                    <x-primary-button>Confirm Distributed</x-primary-button>
                                </form>
                            @else
                                <p class="text-sm text-gray-500">Approved for distribution — awaiting confirmation from the Brand Manager.</p>
                            @endif
                        </div>
                    @elseif ($document->distributionRecords->firstWhere('status', 'distributed'))
                        @php($distributed = $document->distributionRecords->firstWhere('status', 'distributed'))
                        <div class="{{ $cardClass }}">
                            <h3 class="text-base font-semibold text-gray-900 mb-1">Distribution</h3>
                            <p class="text-sm text-gray-500">
                                Distributed via <span class="font-medium text-gray-700">{{ $distributed->distribution_channel }}</span>
                                on {{ $distributed->distribution_date->format('d M Y') }}
                                by {{ $distributed->distributedBy?->name }}.
                            </p>
                        </div>
                    @endif

                    @if (($isOwner || auth()->user()->can('access-admin')) && in_array($document->status, ['approved', 'approved_for_production', 'approved_for_distribution', 'pending_expiration']))
                        <div class="{{ $cardClass }}">
                            <h3 class="text-base font-semibold text-gray-900 mb-3">Lifecycle</h3>
                            @if ($document->legal_hold)
                                <p class="text-xs text-[#8f2323]">⚖️ Locked while under legal hold — release the hold to change lifecycle status.</p>
                            @else
                                @if ($document->status === 'approved_for_production')
                                    <p class="text-xs text-gray-500 mb-2">Closed and Approved for Production. Submit the update for distribution when it is ready to go out.</p>
                                @endif
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($lifecycleActions as $targetStatus => $buttonLabel)
                                        @if ($document->status !== $targetStatus)
                                            <form method="POST" action="{{ route('documents.status.update', $document) }}">
                                                @csrf
                                                <input type="hidden" name="status" value="{{ $targetStatus }}">
                                                <button class="text-xs px-2.5 py-1.5 border border-gray-200 rounded-lg text-gray-600 hover:border-brand-300 hover:text-brand-700 transition">{{ $buttonLabel }}</button>
                                            </form>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                    @endif {{-- agency / everyone else --}}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
