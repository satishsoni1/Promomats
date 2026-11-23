<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RelaysWebActions;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentApprovalController;
use App\Http\Controllers\DocumentCommentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTaskController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReferenceAttachmentController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\WorkTaskController;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentStageAssignee;
use App\Models\DocumentWorkTask;
use Illuminate\Http\Request;

/**
 * Every action the mobile app can take, run through the same web controller the
 * browser uses - so the phone follows exactly the same rules (makers can't be
 * checkers, draft comments, comment lock, offline sign-off, agency confirmation,
 * routing ...). See RelaysWebActions for how the result becomes JSON.
 */
class MobileWorkflowController extends Controller
{
    use RelaysWebActions;

    // ---- the job ----

    public function store(Request $request)
    {
        return $this->relay(fn () => app(DocumentController::class)->store($request), 201);
    }

    public function uploadVersion(Request $request, Document $document)
    {
        return $this->relay(fn () => app(DocumentController::class)->uploadNewVersion($request, $document), 200, $document);
    }

    /** Submit for review / resubmit after Not approved / start review after the draft is aligned. */
    public function submit(Request $request, Document $document)
    {
        return $this->relay(fn () => app(DocumentController::class)->submit($request, $document), 200, $document);
    }

    public function updateStatus(Request $request, Document $document)
    {
        return $this->relay(fn () => app(DocumentController::class)->updateStatus($request, $document), 200, $document);
    }

    public function storeReference(Request $request, Document $document)
    {
        // The web offers this to the Brand Manager who owns the job (and admins).
        abort_unless($request->user()->id === $document->owner_id || $request->user()->can('access-admin'), 403);

        return $this->relay(fn () => app(ReferenceAttachmentController::class)->storeForDocument($request, $document), 201, $document);
    }

    // ---- review ----

    /** Record a decision - including the offline Chairperson sign-off with its signed copy. */
    public function decide(Request $request, Document $document)
    {
        $this->authorize('view', $document);
        $instance = $document->activeWorkflowInstance;
        abort_unless($instance, 422, 'This document is not in review.');

        return $this->relay(fn () => app(DocumentApprovalController::class)->act($request, $instance), 200, $document);
    }

    public function comment(Request $request, Document $document)
    {
        return $this->relay(fn () => app(DocumentCommentController::class)->store($request, $document), 201, $document);
    }

    public function updateComment(Request $request, Document $document, DocumentComment $comment)
    {
        return $this->relay(fn () => app(DocumentCommentController::class)->update($request, $document, $comment), 200, $document);
    }

    public function resolveComment(Request $request, Document $document, DocumentComment $comment)
    {
        return $this->relay(fn () => app(DocumentCommentController::class)->resolve($request, $document, $comment), 200, $document);
    }

    // ---- Brand Manager: stakeholders ----

    public function reassign(Request $request, Document $document, DocumentStageAssignee $task)
    {
        return $this->relay(fn () => app(DocumentTaskController::class)->reassign($request, $document, $task), 200, $document);
    }

    public function updateDue(Request $request, Document $document, DocumentStageAssignee $task)
    {
        return $this->relay(fn () => app(DocumentTaskController::class)->updateDue($request, $document, $task), 200, $document);
    }

    // ---- Content Agency / Design Team work ----

    public function requestWork(Request $request, Document $document)
    {
        return $this->relay(fn () => app(WorkTaskController::class)->store($request, $document), 201, $document);
    }

    public function assignPlaceholder(Request $request, Document $document)
    {
        return $this->relay(fn () => app(WorkTaskController::class)->assignPlaceholder($request, $document), 201, $document);
    }

    public function takeWork(Request $request, DocumentWorkTask $task)
    {
        return $this->relay(fn () => app(WorkTaskController::class)->take($request, $task));
    }

    public function assignWork(Request $request, DocumentWorkTask $task)
    {
        return $this->relay(fn () => app(WorkTaskController::class)->assign($request, $task));
    }

    public function completeWork(Request $request, DocumentWorkTask $task)
    {
        return $this->relay(fn () => app(WorkTaskController::class)->complete($request, $task));
    }

    public function cancelWork(Request $request, DocumentWorkTask $task)
    {
        return $this->relay(fn () => app(WorkTaskController::class)->destroy($request, $task));
    }

    // ---- helpdesk & notifications ----

    public function raiseTicket(Request $request)
    {
        return $this->relay(fn () => app(SupportTicketController::class)->store($request), 201);
    }

    public function readAllNotifications(Request $request)
    {
        return $this->relay(fn () => app(NotificationController::class)->readAll($request));
    }

    public function readNotification(Request $request, string $id)
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead();

        return response()->json(['ok' => true]);
    }
}
