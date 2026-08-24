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
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'exists:document_comments,id'],
            'page_number' => ['nullable', 'integer', 'min:1'],
            'x_position' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_with:page_number'],
            'y_position' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_with:page_number'],
            'timestamp_seconds' => ['nullable', 'numeric', 'min:0'],
        ]);

        $comment = DocumentComment::create([
            'document_id' => $document->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'page_number' => $validated['page_number'] ?? null,
            'x_position' => $validated['x_position'] ?? null,
            'y_position' => $validated['y_position'] ?? null,
            'timestamp_seconds' => $validated['timestamp_seconds'] ?? null,
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
