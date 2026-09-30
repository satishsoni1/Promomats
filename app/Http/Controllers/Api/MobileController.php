<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * JSON API behind the VODO mobile app (mobile/ - Flutter): stakeholders see what's
 * waiting on them, open the creative, read and add comments, and record their
 * decision from a phone. Every rule (visibility, who may decide, Brand Managers
 * never approving) is the same code path the web app uses.
 */
class MobileController extends Controller
{
    public function __construct(protected WorkflowEngine $engine) {}

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

    /** Everything waiting on the user - same buckets as the web's Active Workflow. */
    public function tasks(Request $request)
    {
        $user = $request->user()->loadMissing('roles');

        $approvals = $user->pendingApprovals()
            ->with(['instance.document.owner', 'instance.document.documentType', 'stage'])
            ->get()
            ->sortBy(fn (DocumentStageAssignee $a) => $a->dueAt()?->timestamp ?? PHP_INT_MAX)
            ->values()
            ->map(fn (DocumentStageAssignee $a) => [
                'task_id' => $a->id,
                'document' => $this->documentSummary($a->instance->document),
                'stage' => $a->stage->name,
                'is_draft' => $a->stage->isDraft(),
                'due_at' => $a->dueAt()?->toIso8601String(),
                'is_overdue' => $a->isOverdue(),
                'is_due_soon' => $a->isDueSoon(),
            ]);

        $revisions = Document::where('owner_id', $user->id)
            ->where('status', 'approved_with_changes_pending')
            ->with('owner', 'documentType')
            ->latest('status_changed_at')
            ->get()
            ->map(fn ($d) => $this->documentSummary($d));

        $work = $user->openWorkTasksQuery()->with('document.owner', 'document.documentType', 'assignee')->get()
            ->map(fn ($t) => [
                'work_task_id' => $t->id,
                'type' => $t->typeLabel(),
                'document' => $this->documentSummary($t->document),
                'assigned_to' => $t->assignee?->name,
                'instructions' => $t->instructions,
                'due_at' => $t->due_at?->toIso8601String(),
            ]);

        return response()->json(compact('approvals', 'revisions', 'work'));
    }

    public function show(Request $request, Document $document)
    {
        $this->authorize('view', $document);
        $user = $request->user();

        $document->load([
            'owner', 'documentType', 'brand', 'currentVersion', 'workflowTemplate.stages',
            'activeWorkflowInstance.pendingAssignees.user', 'activeWorkflowInstance.pendingAssignees.stage',
            'activeWorkflowInstance.stageRuns', 'approvalActions.actor', 'approvalActions.stage',
        ]);

        $instance = $document->activeWorkflowInstance;
        $myTask = $instance?->pendingAssignees->firstWhere('user_id', $user->id);
        $runs = $instance?->stageRuns->keyBy('workflow_stage_id') ?? collect();

        $comments = DocumentComment::where('document_id', $document->id)->whereNull('parent_id')
            ->with('author', 'replies.author')->latest()->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'author' => $c->author?->name,
                'body' => $c->body,
                'page' => $c->page_number,
                'selected_text' => $c->selected_text,
                'replacement_text' => $c->replacement_text,
                'resolved' => $c->resolved_at !== null,
                'created_at' => $c->created_at->toIso8601String(),
                'replies' => $c->replies->map(fn ($r) => ['id' => $r->id, 'author' => $r->author?->name, 'body' => $r->body, 'created_at' => $r->created_at->toIso8601String()]),
            ]);

        return response()->json([
            'document' => $this->documentSummary($document) + [
                'description' => $document->description,
                'brand' => $document->brand?->name,
                'channel' => $document->channelLabel(),
                'due_date' => $document->due_date?->toDateString(),
                'expiry_date' => $document->expiry_date?->toDateString(),
                'workflow' => $document->workflowTemplate?->name,
            ],
            'file' => $this->filePayload($document->currentVersion),
            'stages' => ($document->workflowTemplate?->stages ?? collect())->map(fn ($s) => [
                'name' => $s->name,
                'parallel_group' => $s->parallel_group,
                'status' => $runs[$s->id]->status ?? 'upcoming',
                'decision' => $runs[$s->id]->decision ?? null,
                'with' => $instance?->pendingAssignees->where('workflow_stage_id', $s->id)->pluck('user.name')->values(),
            ]),
            'my_task' => $myTask ? [
                'task_id' => $myTask->id,
                'stage' => $myTask->stage->name,
                'is_draft' => $myTask->stage->isDraft(),
                'due_at' => $myTask->dueAt()?->toIso8601String(),
                'can_decide' => $user->canRecordDecisions(),
            ] : null,
            'history' => $document->approvalActions->map(fn ($a) => [
                'by' => $a->actor?->name,
                'stage' => $a->stage?->name,
                'decision' => $a->decision,
                'decision_label' => $a->decisionLabel(),
                'comments' => $a->comments,
                'at' => $a->acted_at?->toIso8601String(),
            ]),
            'comments' => $comments,
        ]);
    }

    public function decide(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,approved_with_changes,not_approved'],
            'comments' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validated['decision'] !== 'approved' && blank($validated['comments'] ?? null)) {
            throw ValidationException::withMessages(['comments' => 'Comments are required when approving with changes or rejecting.']);
        }

        $instance = $document->activeWorkflowInstance;
        abort_unless($instance, 422, 'This document is not in review.');

        $this->engine->recordDecision($instance, $request->user(), $validated['decision'], $validated['comments'] ?? null, $request->ip());

        return response()->json(['ok' => true, 'status' => $document->fresh()->status]);
    }

    public function comment(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        if (! empty($validated['parent_id'])) {
            abort_unless(DocumentComment::where('id', $validated['parent_id'])->where('document_id', $document->id)->exists(), 422);
        }

        $comment = DocumentComment::create([
            'document_id' => $document->id,
            'document_version_id' => empty($validated['parent_id']) ? $document->current_version_id : null,
            'parent_id' => $validated['parent_id'] ?? null,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        return response()->json(['id' => $comment->id], 201);
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

    protected function filePayload(?DocumentVersion $version): ?array
    {
        if (! $version) {
            return null;
        }

        return [
            'version_no' => $version->version_no,
            'name' => $version->original_filename,
            'kind' => $version->isPdf() ? 'pdf' : ($version->isImage() ? 'image' : ($version->isVideo() ? 'video' : 'other')),
            'size' => $version->humanFileSize(),
            'url' => URL::temporarySignedRoute('api.files.show', now()->addMinutes(30), ['version' => $version->id]),
        ];
    }

    protected function documentSummary(Document $d): array
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
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name'),
            'action_count' => $user->actionRequiredCount(),
        ];
    }
}
