<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;

class DocumentObserverController extends Controller
{
    /**
     * "Assign an observer in the flow" - a read-only stakeholder attached to a
     * document who isn't an approver but wants visibility into its status. Backed by
     * the existing document_watchers pivot (already wired into every notification
     * path - WorkflowEngine::stakeholderIds(), DocumentController::notifyStakeholders())
     * which previously had no UI to actually assign anyone to it.
     */
    public function store(Request $request, Document $document)
    {
        $this->authorizeManage($request, $document);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        abort_if((int) $validated['user_id'] === $document->owner_id, 422, 'The owner is already a stakeholder on this document.');

        $document->watchers()->syncWithoutDetaching([$validated['user_id']]);

        $observer = User::find($validated['user_id']);

        return back()->with('status', "{$observer->name} added as an observer — they'll be notified of status changes and can view this document's progress.");
    }

    public function destroy(Request $request, Document $document, User $user)
    {
        $this->authorizeManage($request, $document);

        $document->watchers()->detach($user->id);

        return back()->with('status', "{$user->name} removed as an observer.");
    }

    protected function authorizeManage(Request $request, Document $document): void
    {
        abort_unless(
            $request->user()->id === $document->owner_id || $request->user()->can('access-admin'),
            403,
            'Only the document owner or an admin can manage observers.'
        );
    }
}
