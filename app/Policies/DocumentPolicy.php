<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * A job in a workflow is visible only to its stakeholders; finished (approved)
     * material is the shared library everyone may browse. The rule itself lives in
     * Document::scopeVisibleTo() so lists and single pages always agree.
     */
    public function view(User $user, Document $document): bool
    {
        return $document->isVisibleTo($user);
    }

    /**
     * Starting a new job / uploading a file. MLR reviewers are review-only (UAT
     * feedback: "remove document upload access for the MLR team").
     */
    public function create(User $user): bool
    {
        return $user->canUploadDocuments();
    }

    /**
     * Handing a pending task to another stakeholder, choosing stakeholders and due
     * dates, and sending rework to the Design Team: the task owner (or an admin).
     */
    public function manageTasks(User $user, Document $document): bool
    {
        return $user->id === $document->owner_id || $user->can('access-admin');
    }

    /**
     * Metadata changes (status, project/cycle assignment) - the document owner,
     * or an admin. Mirrors the abort_unless() checks this replaced in
     * DocumentController.
     */
    public function update(User $user, Document $document): bool
    {
        return $user->id === $document->owner_id
            || $user->can('access-admin')
            || $document->openToDepartmentEditor($user);
    }

    /**
     * Uploading a new version (content, not just metadata) is open to the
     * document owner, an admin, or anyone on the Agency - agencies routinely
     * produce/revise the artwork itself, they just don't get to decide when
     * it's ready to go into the approval flow (see submitForReview() below).
     */
    public function uploadVersion(User $user, Document $document): bool
    {
        if ($document->legal_hold || ! $user->canUploadDocuments()) {
            return false;
        }

        return $user->id === $document->owner_id
            || $user->can('access-admin')
            || $user->hasRole('agency')
            || $document->openToDepartmentEditor($user)
            // "Option of uploading a revised/new document at all stages": whoever
            // currently holds a stage (other than MLR, excluded above) ...
            || $document->activeWorkflowInstance?->pendingAssignees()->where('user_id', $user->id)->exists()
            // ... and the Design Team, who produce the artwork and its revisions.
            || $user->isDesignTeam()
            || $document->workTasks()->open()->where('assigned_to', $user->id)->exists();
    }

    /**
     * Sending a document into (or back into, after a revision) the approval
     * workflow is deliberately narrower than uploadVersion(): only the owner
     * (or an admin) decides a version is ready to be reviewed, even though an
     * agency user may have been the one who uploaded it.
     */
    public function submitForReview(User $user, Document $document): bool
    {
        return $user->id === $document->owner_id || $user->can('access-admin');
    }

    /**
     * Freezing/unfreezing a document for litigation or regulatory inquiry is an
     * admin/compliance decision, never a document-ownership one.
     */
    public function manageLegalHold(User $user, Document $document): bool
    {
        return $user->can('access-admin');
    }

    /**
     * Confirming an actual distribution (channel/date) - same bar as update():
     * the document owner, or an admin/distribution manager.
     */
    public function manageDistribution(User $user, Document $document): bool
    {
        return $user->id === $document->owner_id || $user->can('access-admin');
    }

    /**
     * The /reports suite (spec REQ-48) - anyone with the seeded "View Reports"
     * permission, or an admin. A class-level ability (no specific Document
     * instance) since reports aggregate across all documents.
     */
    public function viewReports(User $user): bool
    {
        return $user->hasPermission('view-reports') || $user->can('access-admin');
    }
}
