<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\User;
use App\Notifications\DocumentActionNotification;
use Illuminate\Http\Request;

class DocumentCommentController extends Controller
{
    /**
     * Post a comment (or a reply to one) on a document. Any user who can view the
     * document can comment - mirrors PromoMats' inline review-comment threads. A
     * comment can sit in the general whole-document thread, be anchored to an exact
     * page/x/y position on a PDF page (the inline "sticky comment" pins), or (REQ-2.1)
     * anchored to a moment in time on a video, driven by the optional
     * page_number/x_position/y_position or timestamp_seconds fields.
     */
    public function store(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'exists:document_comments,id'],
            'page_number' => ['nullable', 'integer', 'min:1'],
            'x_position' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_with:page_number'],
            'y_position' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_with:page_number'],
            'timestamp_seconds' => ['nullable', 'numeric', 'min:0'],
            // Text-selection ("highlight") comments on a PDF: the selected words plus
            // one box per highlighted line, all as 0-100 page percentages.
            // Area highlights on artwork have boxes but no selectable text.
            'selected_text' => ['nullable', 'string', 'max:2000'],
            // The reviewer's proposed wording for the highlighted text.
            'replacement_text' => ['nullable', 'string', 'max:2000'],
            'highlight_rects' => ['nullable', 'array', 'max:100', 'required_with:selected_text'],
            'highlight_rects.*.x' => ['required', 'numeric', 'min:0', 'max:100'],
            'highlight_rects.*.y' => ['required', 'numeric', 'min:0', 'max:100'],
            'highlight_rects.*.w' => ['required', 'numeric', 'min:0', 'max:100'],
            'highlight_rects.*.h' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $isAnchoredToPage = isset($validated['page_number']);

        $comment = DocumentComment::create([
            'document_id' => $document->id,
            // Top-level comments remember the version they were made on; replies
            // inherit their thread's context, so they don't need one.
            'document_version_id' => empty($validated['parent_id']) ? $document->current_version_id : null,
            'parent_id' => $validated['parent_id'] ?? null,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'page_number' => $validated['page_number'] ?? null,
            'x_position' => $validated['x_position'] ?? null,
            'y_position' => $validated['y_position'] ?? null,
            'timestamp_seconds' => $validated['timestamp_seconds'] ?? null,
            'selected_text' => $isAnchoredToPage ? ($validated['selected_text'] ?? null) : null,
            'replacement_text' => $isAnchoredToPage ? ($validated['replacement_text'] ?? null) : null,
            'highlight_rects' => $isAnchoredToPage ? ($validated['highlight_rects'] ?? null) : null,
        ]);

        $this->notifyThread($document, $comment, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'comment' => $comment->load('author'),
            ]);
        }

        return back()->with('status', 'Comment posted.')->withFragment('comment-' . $comment->id);
    }

    /**
     * Edit your own comment (or reply) after posting it. Only the author may edit;
     * the edit is timestamped so readers can see it changed.
     */
    public function update(Request $request, Document $document, DocumentComment $comment)
    {
        $this->authorize('view', $document);
        abort_unless($comment->document_id === $document->id, 404);
        abort_unless($comment->user_id === $request->user()->id, 403, 'Only the author can edit a comment.');

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'replacement_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $comment->update([
            'body' => $validated['body'],
            'replacement_text' => $comment->page_number ? ($validated['replacement_text'] ?? null) : $comment->replacement_text,
            'edited_at' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json(['comment' => $comment->only('id', 'body', 'replacement_text') + ['edited' => true]]);
        }

        return back()->with('status', 'Comment updated.')->withFragment('comment-' . $comment->id);
    }

    /**
     * Mark a comment thread resolved (or reopen it). Open to whoever raised it, the
     * document owner, and admins - the people who'd reasonably decide it's been
     * dealt with.
     */
    public function resolve(Request $request, Document $document, DocumentComment $comment)
    {
        $this->authorize('view', $document);
        abort_unless($comment->document_id === $document->id && $comment->parent_id === null, 404);

        $user = $request->user();
        abort_unless(
            $user->id === $comment->user_id || $user->id === $document->owner_id || $user->can('access-admin'),
            403
        );

        $resolved = $request->boolean('resolved', true);
        $comment->update([
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? $user->id : null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'resolved' => $resolved,
                'resolved_by' => $resolved ? $user->name : null,
            ]);
        }

        return back()->with('status', $resolved ? 'Comment resolved.' : 'Comment reopened.');
    }

    /**
     * Notify the document owner, anyone currently pending approval on it, and everyone
     * else who has already commented in the thread - excluding whoever just posted.
     */
    protected function notifyThread(Document $document, DocumentComment $comment, User $actor): void
    {
        $recipientIds = collect([$document->owner_id])
            ->merge(
                $document->activeWorkflowInstance?->pendingAssignees()->pluck('user_id') ?? collect()
            )
            ->merge(
                DocumentComment::where('document_id', $document->id)->pluck('user_id')
            )
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $actor->id)
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $recipientIds)->where('is_active', true)->get();

        foreach ($users as $user) {
            $user->notify(new DocumentActionNotification(
                document: $document,
                event: 'comment_added',
                actor: $actor,
                comments: $comment->body,
            ));
        }
    }
}
