<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Documents behave like a shared library across the org (search, the claims
     * library, and the reference library all work the same way) - any
     * authenticated user can look one up. This is the single place that rule
     * lives, so tightening it later (e.g. department-scoped visibility, or
     * hiding drafts from non-owners) is a one-line change here rather than a
     * hunt through every controller that touches a Document.
     */
    public function view(User $user, Document $document): bool
    {
        return true;
    }

    /**
     * Metadata changes (status, project/cycle assignment) - the document owner,
     * or an admin. Mirrors the abort_unless() checks this replaced in
     * DocumentController.
     */
    public function update(User $user, Document $document): bool
    {
        return $user->id === $document->owner_id || $user->can('access-admin');
    }

    /**
     * Uploading a new version (content, not just metadata) is open to the
     * document owner, an admin, or anyone on the Agency - agencies routinely
     * produce/revise the artwork itself, they just don't get to decide when
     * it's ready to go into the approval flow (see submitForReview() below).
     */
    public function uploadVersion(User $user, Document $document): bool
    {
        return $user->id === $document->owner_id || $user->can('access-admin') || $user->hasRole('agency');
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
