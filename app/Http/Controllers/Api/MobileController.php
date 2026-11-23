<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ActiveWorkflowController;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkTask;
use App\Models\ReferenceAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * JSON API behind the VODO mobile app (mobile/ - Flutter). Reads live here: sign-in,
 * the Active Workflow lists and the full job page. Every action goes through
 * MobileWorkflowController, which runs the web controllers themselves - so the app
 * and the browser always follow the same rules.
 */
class MobileController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $key = 'mobile-login:' . strtolower($validated['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in a minute.']);
        }

        $user = User::where('email', $validated['email'])->first();
        if (! $user || ! $user->is_active || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        RateLimiter::clear($key);

        [, $plain] = ApiToken::issue($user, $validated['device_name'] ?? 'Mobile app');

        return response()->json(['token' => $plain, 'user' => $this->userPayload($user)]);
    }

    public function logout(Request $request)
    {
        $request->attributes->get('api_token')?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * The web's Active Workflow, all tabs at once: what needs my action, my jobs in
     * flight, the Design Team / Content Agency queues, and the Brand Manager views.
     */
    public function tasks(Request $request)
    {
        $user = $request->user()->loadMissing('roles');
        $data = app(ActiveWorkflowController::class)->index($request)->getData();

        $approvals = $data['myApprovals']->map(fn (DocumentStageAssignee $a) => [
            'task_id' => $a->id,
            'document' => self::summarise($a->instance->document),
            'stage' => $a->stage->name,
            'is_draft' => $a->stage->isDraft(),
            'is_offline' => $a->stage->isOffline(),
            'due_at' => $a->dueAt()?->toIso8601String(),
            'is_overdue' => $a->isOverdue(),
            'is_due_soon' => $a->isDueSoon(),
            'reassigned' => $a->reassigned_from_id !== null,
        ])->values();

        $revisions = $data['myRevisions']->map(function (Document $d) {
            $last = $d->approvalActions->first();

            return self::summarise($d) + [
                'reason' => $d->status === 'draft_aligned'
                    ? 'Draft aligned' . ($last ? ' by ' . $last->actor?->name : '') . ' - assign the creative, then start the review'
                    : ($last ? $last->decisionLabel() . ' by ' . $last->actor?->name . ' at ' . $last->stage?->name : 'Revision needed'),
            ];
        })->values();

        $work = $data['myWorkTasks']->map(fn (DocumentWorkTask $t) => $this->workTaskPayload($t, $user))->values();

        return response()->json([
            'approvals' => $approvals,
            'revisions' => $revisions,
            'work' => $work,
            'my_jobs' => $data['myJobs']->map(fn ($d) => $this->jobRow($d))->values(),
            'design_queue' => $data['designQueue']->map(fn ($t) => $this->workTaskPayload($t, $user))->values(),
            'agency_queue' => $data['agencyQueue']->map(fn ($t) => $this->workTaskPayload($t, $user))->values(),
            'show_design_queue' => $data['designQueue']->isNotEmpty() || $user->isDesignTeam(),
            'show_agency_queue' => $data['agencyQueue']->isNotEmpty() || $user->isAgency(),
            'brand_managers' => $data['brandManagers']->map->only('id', 'name')->values(),
            'selected_bm' => $data['selectedBm']?->only('id', 'name'),
            'bm_jobs' => $data['bmJobs']->map(fn ($d) => $this->jobRow($d))->values(),
            'counts' => $data['counts'],
        ]);
    }

    /** The full job page, shaped for the phone. */
    public function show(Request $request, Document $document)
    {
        $this->authorize('view', $document);
        $user = $request->user()->loadMissing('roles');

        $document->load([
            'owner', 'documentType', 'brand', 'project', 'currentVersion', 'workflowTemplate.stages', 'versions.uploader',
            'activeWorkflowInstance.pendingAssignees.user', 'activeWorkflowInstance.pendingAssignees.stage',
            'activeWorkflowInstance.pendingAssignees.reassignedBy', 'activeWorkflowInstance.stageRuns',
            'activeWorkflowInstance.resumeAtStage', 'activeWorkflowInstance.currentStage',
            'approvalActions.actor', 'approvalActions.stage', 'referenceAttachments.uploader',
            'workTasks.assignee', 'workTasks.requester',
        ]);

        $instance = $document->activeWorkflowInstance;
        $isOwner = $user->id === $document->owner_id;
        $isAdmin = $user->can('access-admin');
        $canManage = $user->can('manageTasks', $document);
        $myTask = $instance?->pendingAssignees->firstWhere('user_id', $user->id);
        $lastDecision = $document->approvalActions->first();
        $openWork = $document->workTasks->firstWhere('status', 'open');
        $isParked = $document->status === 'approved_with_changes_pending';
        $isDraftAligned = $document->status === 'draft_aligned';
        $locked = $document->commentsLockedFor($user);

        return response()->json([
            'document' => self::summarise($document) + [
                'description' => $document->description,
                'brand' => $document->brand?->name,
                'channel' => $document->channelLabel(),
                'type' => $document->documentType?->name,
                'category' => $document->category,
                'products' => $document->products ?? [],
                'countries' => $document->countries ?? [],
                'target_audience' => $document->targetAudienceLabel(),
                'project' => $document->project?->name,
                'due_date' => $document->due_date?->toDateString(),
                'start_date' => $document->start_date?->toDateString(),
                'expiry_date' => $document->expiry_date?->toDateString(),
                'workflow' => $document->workflowTemplate?->name,
                'is_placeholder' => (bool) $document->is_placeholder,
                'legal_hold' => (bool) $document->legal_hold,
                'web_url' => route('documents.show', $document),
            ],
            'permissions' => [
                'is_owner' => $isOwner,
                'is_admin' => $isAdmin,
                'can_manage_tasks' => $canManage,
                'can_upload_version' => $user->can('uploadVersion', $document),
                'can_submit' => $user->can('submitForReview', $document),
                'can_add_reference' => $isOwner || $isAdmin,
                'comments_locked' => $locked,
                'comments_draft' => $document->commentsStartAsDraftFor($user),
            ],
            'banner' => $this->banner($document, $openWork, $lastDecision, $isParked, $isDraftAligned, $locked),
            'file' => $this->filePayload($document->currentVersion),
            'versions' => $document->versions->sortByDesc('version_no')->values()->map(fn (DocumentVersion $v) => $this->filePayload($v) + [
                'uploaded_by' => $v->uploader?->name,
                'notes' => $v->change_notes,
                'is_current' => (bool) $v->is_current,
                'created_at' => $v->created_at?->toIso8601String(),
            ]),
            'references' => $document->referenceAttachments->map(fn (ReferenceAttachment $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'name' => $r->original_filename,
                'size' => $r->humanFileSize(),
                'uploaded_by' => $r->uploader?->name,
                'url' => URL::temporarySignedRoute('api.references.show', now()->addMinutes(30), ['reference' => $r->id]),
            ]),
            'stages' => $this->stagePayload($document, $isParked),
            'in_parallel' => $instance ? $instance->stageRuns->where('status', 'open')->count() > 1 : false,
            'pending' => $instance ? $instance->pendingAssignees->map(fn (DocumentStageAssignee $a) => [
                'task_id' => $a->id,
                'stage' => $a->stage?->name,
                'user' => $a->user?->name,
                'due_at' => $a->dueAt()?->toIso8601String(),
                'is_overdue' => $a->isOverdue(),
                'is_due_soon' => $a->isDueSoon(),
                'note' => $a->reassigned_from_id ? 'Reassigned by ' . $a->reassignedBy?->name . ($a->reassign_reason ? " ({$a->reassign_reason})" : '') : null,
            ])->values() : [],
            'reassign_options' => $canManage && $instance
                ? User::with('roles')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                    ->filter(fn ($u) => $u->canRecordDecisions() && $u->id !== $document->owner_id)
                    ->map->only('id', 'name')->values()
                : [],
            'my_task' => $myTask ? [
                'task_id' => $myTask->id,
                'stage' => $myTask->stage->name,
                'is_draft' => $myTask->stage->isDraft(),
                'is_offline' => $myTask->stage->isOffline(),
                'is_final' => ! $myTask->stage->nextStage(),
                'due_at' => $myTask->dueAt()?->toIso8601String(),
                // Makers can't be checkers: Brand Managers and the job's own owner never decide.
                'can_decide' => $user->canRecordDecisions() && ! $isOwner,
            ] : null,
            'start_panel' => $isOwner && $document->status === 'draft' ? [
                'first_stage_is_draft' => (bool) $document->workflowTemplate?->stages->first()?->isDraft(),
                'has_file' => $document->currentVersion !== null,
                'can_assign_placeholder' => $document->is_placeholder && ! $openWork,
            ] : null,
            'owner_panel' => ($isOwner || $canManage) && ($isParked || $isDraftAligned) ? [
                'mode' => $isDraftAligned ? 'draft_aligned' : 'revise',
                'last_decision' => $lastDecision ? [
                    'by' => $lastDecision->actor?->name,
                    'decision' => $lastDecision->decisionLabel(),
                    'stage' => $lastDecision->stage?->name,
                    'comments' => $lastDecision->comments,
                ] : null,
                'resume_stage' => $instance?->resumeAtStage?->name,
                'resubmit_to' => $lastDecision?->stage?->name,
                'upload_to_confirm' => $instance && $document->current_version_id !== $instance->document_version_id && $document->currentVersion ? [
                    'version_no' => $document->currentVersion->version_no,
                    'by' => $document->currentVersion->uploader?->name,
                    'at' => $document->currentVersion->created_at?->toIso8601String(),
                ] : null,
                'can_skip_confirmation' => $isParked,
            ] : null,
            'work_task' => $openWork ? $this->workTaskPayload($openWork, $user) : null,
            'team_members' => $canManage ? [
                'design' => User::teamMembers('design')->get(['id', 'name'])->map->only('id', 'name'),
                'agency' => User::teamMembers('agency')->get(['id', 'name'])->map->only('id', 'name'),
            ] : null,
            'lifecycle' => ($isOwner || $isAdmin) && ! $document->legal_hold
                && in_array($document->status, ['approved', 'approved_for_production', 'approved_for_distribution', 'pending_expiration'], true)
                ? collect($document->status === 'approved_for_production' ? ['approved_for_distribution' => 'Release for Distribution'] : [])
                    ->merge(['superseded' => 'Mark Superseded', 'obsolete' => 'Mark Obsolete', 'archived' => 'Archive'])
                    ->map(fn ($label, $status) => ['status' => $status, 'label' => $label])->values()
                : [],
            'history' => $document->approvalActions->map(fn ($a) => [
                'by' => $a->actor?->name,
                'stage' => $a->stage?->name,
                'decision' => $a->decision,
                'decision_label' => $a->decisionLabel(),
                'comments' => $a->comments,
                'at' => $a->acted_at?->toIso8601String(),
            ]),
            'comments' => $this->comments($document, $user, $locked),
        ]);
    }

    /**
     * Short-lived signed link the app can load without a header (PDF renderer,
     * image widget, or the phone's own viewer for other types).
     */
    public function file(Request $request, DocumentVersion $version)
    {
        return Storage::disk($version->disk)->response($version->file_path, $version->original_filename, [
            'Content-Type' => match (true) {
                $version->isPdf() => 'application/pdf',
                $version->isImage() && $version->extension() !== 'svg' => 'image/' . ($version->extension() === 'jpg' ? 'jpeg' : $version->extension()),
                default => 'application/octet-stream',
            },
        ]);
    }

    // ------------------------------------------------------------------

    /** The stepper: one state per stage, matching the web page's colours. */
    protected function stagePayload(Document $document, bool $isParked): array
    {
        $instance = $document->activeWorkflowInstance;
        $stages = $document->workflowTemplate?->stages ?? collect();
        $runs = $instance?->stageRuns->keyBy('workflow_stage_id') ?? collect();
        $openSeqs = $stages->filter(fn ($s) => ($runs[$s->id]->status ?? null) === 'open')->pluck('sequence_no');
        $assignedStageIds = $instance ? $instance->assignees()->pluck('workflow_stage_id')->unique() : collect();
        $fullyApproved = in_array($document->status, ['approved', 'approved_for_production', 'approved_for_distribution'], true);
        $lastActionSeq = $document->approvalActions->first()?->stage?->sequence_no;
        $currentSeq = $instance?->currentStage?->sequence_no;

        return $stages->map(function ($s) use ($document, $instance, $runs, $openSeqs, $assignedStageIds, $fullyApproved, $lastActionSeq, $currentSeq, $isParked) {
            $run = $runs[$s->id] ?? null;
            $state = match (true) {
                $fullyApproved => 'done',
                $document->status === 'rejected' => $lastActionSeq && $s->sequence_no <= $lastActionSeq ? 'rejected' : 'upcoming',
                $run?->status === 'resolved' && ! $assignedStageIds->contains($s->id) => 'skipped',
                $openSeqs->contains($s->sequence_no) => 'current',
                $isParked && $instance && ($s->parallel_group ? $s->parallel_group === $instance->currentStage?->parallel_group : $s->id === $instance->current_stage_id) => 'returned',
                $currentSeq && $s->sequence_no < $currentSeq, $run?->status === 'resolved' => 'done',
                default => 'upcoming',
            };

            return [
                'name' => $s->name,
                'sequence_no' => $s->sequence_no,
                'parallel_group' => $s->parallel_group,
                'stage_type' => $s->stage_type ?? 'review',
                'state' => $state,
                'with' => $instance?->pendingAssignees->where('workflow_stage_id', $s->id)->pluck('user.name')->values() ?? [],
            ];
        })->values()->all();
    }

    protected function banner(Document $d, ?DocumentWorkTask $work, $last, bool $isParked, bool $isDraftAligned, bool $locked): ?array
    {
        $who = fn () => $work ? 'the ' . $work->teamLabel() . ($work->assignee ? ' (' . $work->assignee->name . ')' : '') : $d->owner?->name . ' (Brand Manager)';

        return match (true) {
            (bool) $d->legal_hold => ['tone' => 'danger', 'text' => 'Legal hold - this job is frozen: no new versions or edits.'],
            (bool) $d->is_placeholder => ['tone' => 'info', 'text' => 'Placeholder - waiting for the file. ' . ($work ? 'With ' . $who() . '.' : 'The Brand Manager uploads the Word draft or assigns the creative.')],
            $isDraftAligned => ['tone' => 'info', 'text' => 'Draft aligned' . ($last ? ' by ' . $last->actor?->name : '') . '. ' . ($work ? 'The creative is with ' . $who() . '; it comes back to the Brand Manager to confirm.' : 'Waiting on ' . $who() . ' to assign the creative and start the review.')],
            $isParked => ['tone' => 'warning', 'text' => 'Waiting on ' . $who() . ' to upload the revision' . ($last ? ' - ' . $last->decisionLabel() . ' by ' . $last->actor?->name . ' at ' . $last->stage?->name : '') . '.' . ($last?->decision === 'not_approved' ? ' Once resubmitted it goes straight back to ' . $last->stage?->name . '.' : '')],
            $locked => ['tone' => 'muted', 'text' => "You've submitted your review, so this job is locked for you while it is with other stakeholders - you can view it but not comment."],
            default => null,
        };
    }

    protected function comments(Document $document, User $user, bool $locked): array
    {
        $isManager = in_array($user->id, [$document->owner_id], true) || $user->can('access-admin');

        return DocumentComment::where('document_id', $document->id)->whereNull('parent_id')
            ->with('author', 'replies.author')->latest()->get()
            ->map(fn (DocumentComment $c) => [
                'id' => $c->id,
                'author' => $c->author?->name,
                'body' => $c->body,
                'page' => $c->page_number,
                'x' => $c->x_position,
                'y' => $c->y_position,
                'selected_text' => $c->selected_text,
                'replacement_text' => $c->replacement_text,
                'resolved' => $c->resolved_at !== null,
                'draft' => $c->published_at === null,
                'edited' => $c->edited_at !== null,
                'can_edit' => $c->user_id === $user->id && ! $locked,
                'can_resolve' => $c->user_id === $user->id || $isManager,
                'created_at' => $c->created_at->toIso8601String(),
                'replies' => $c->replies->map(fn ($r) => [
                    'id' => $r->id,
                    'author' => $r->author?->name,
                    'body' => $r->body,
                    'draft' => $r->published_at === null,
                    'edited' => $r->edited_at !== null,
                    'can_edit' => $r->user_id === $user->id && ! $locked,
                    'created_at' => $r->created_at->toIso8601String(),
                ])->values(),
            ])->values()->all();
    }

    protected function workTaskPayload(DocumentWorkTask $t, User $user): array
    {
        $inTeam = $user->inTeam($t->team);

        return [
            'work_task_id' => $t->id,
            'type' => $t->typeLabel(),
            'team' => $t->team,
            'team_label' => $t->teamLabel(),
            'document' => self::summarise($t->document),
            'assigned_to' => $t->assignee?->name,
            'assigned_to_id' => $t->assigned_to,
            'requested_by' => $t->requester?->name,
            'instructions' => $t->instructions,
            'due_at' => $t->due_at?->toIso8601String(),
            'is_overdue' => $t->isOverdue(),
            'resubmit_on_upload' => (bool) $t->resubmit_on_upload,
            'can_take' => $inTeam && $t->assigned_to !== $user->id,
            'can_assign' => $inTeam || $user->can('manageTasks', $t->document),
            'can_upload' => $t->assigned_to === $user->id || ($t->assigned_to === null && $inTeam),
            'can_withdraw' => $user->can('manageTasks', $t->document),
            'members' => ($inTeam || $user->can('manageTasks', $t->document)) ? User::teamMembers($t->team)->get(['id', 'name'])->map->only('id', 'name') : [],
        ];
    }

    /** A job in flight and who holds it now (the web's job table). */
    protected function jobRow(Document $d): array
    {
        $pending = $d->activeWorkflowInstance?->pendingAssignees ?? collect();
        $work = $d->workTasks->first();

        return self::summarise($d) + [
            'with' => match (true) {
                (bool) $work => $work->teamLabel() . ($work->assignee ? ' - ' . $work->assignee->name : ' (unassigned)'),
                $pending->isNotEmpty() => $pending->groupBy('workflow_stage_id')->map(fn ($g) => $g->first()->stage?->name . ': ' . $g->pluck('user.name')->join(', '))->join(' · '),
                $d->status === 'approved_with_changes_pending' => 'Brand Manager - revision needed',
                $d->status === 'draft_aligned' => 'Brand Manager - draft aligned, assign creative / start review',
                $d->status === 'draft' => 'Brand Manager - not yet submitted',
                default => null,
            },
            'next_due' => $pending->map(fn ($a) => $a->dueAt())->filter()->min()?->toIso8601String(),
        ];
    }

    protected function filePayload(?DocumentVersion $version): ?array
    {
        if (! $version) {
            return null;
        }

        return [
            'id' => $version->id,
            'version_no' => $version->version_no,
            'name' => $version->original_filename,
            'kind' => $version->isPdf() ? 'pdf' : ($version->isImage() ? 'image' : ($version->isVideo() ? 'video' : ($version->isWordDocument() ? 'word' : 'other'))),
            'size' => $version->humanFileSize(),
            'url' => URL::temporarySignedRoute('api.files.show', now()->addMinutes(30), ['version' => $version->id]),
        ];
    }

    public static function summarise(Document $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'reference_no' => $d->reference_no,
            'status' => $d->status,
            'status_label' => $d->is_placeholder ? 'Placeholder' : $d->statusLabel(),
            'collateral' => collect([$d->channelLabel(), $d->documentType?->name])->filter()->join(' · ') ?: null,
            'owner' => $d->owner?->name,
        ];
    }

    protected function userPayload(User $user): array
    {
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name'),
            'action_count' => $user->actionRequiredCount(),
            'unread_notifications' => $user->unreadNotifications()->count(),
            'can_upload' => $user->canUploadDocuments(),
            'can_decide' => $user->canRecordDecisions(),
            'is_brand_manager' => $user->hasAnyRole(config('promomats.roles.brand_manager', ['brand-manager'])),
            'is_design_team' => $user->isDesignTeam(),
            'is_agency' => $user->isAgency(),
            'is_admin' => $user->can('access-admin'),
        ];
    }
}
