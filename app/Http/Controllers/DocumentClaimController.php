<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\ContentModule;
use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentActionNotification;
use Illuminate\Http\Request;

class DocumentClaimController extends Controller
{
    /**
     * Insert one claim, or every claim in a content module at once, into a document -
     * this is the "where used" link the claims library and modular-content library
     * both track back to.
     */
    public function store(Request $request, Document $document)
    {
        $this->authorizeManage($request, $document);

        $validated = $request->validate([
            'claim_id' => ['nullable', 'exists:claims,id'],
            'content_module_id' => ['nullable', 'exists:content_modules,id'],
        ]);

        $claimIds = collect();

        if (! empty($validated['claim_id'])) {
            $claimIds->push($validated['claim_id']);
        }

        if (! empty($validated['content_module_id'])) {
            $claimIds = $claimIds->merge(
                ContentModule::findOrFail($validated['content_module_id'])->claims->pluck('id')
            );
        }

        if ($claimIds->isEmpty()) {
            return back()->withErrors(['claim' => 'Choose a claim or a content module to insert.']);
        }

        foreach ($claimIds->unique() as $claimId) {
            $document->claims()->syncWithoutDetaching([
                $claimId => [
                    'document_version_id' => $document->current_version_id,
                    'inserted_by' => $request->user()->id,
                ],
            ]);
        }

        if ($document->owner_id !== $request->user()->id) {
            $owner = User::find($document->owner_id);
            $owner?->notify(new DocumentActionNotification(document: $document, event: 'claim_inserted', actor: $request->user()));
        }

        return back()->with('status', 'Claim(s) inserted into document.');
    }

    public function destroy(Request $request, Document $document, Claim $claim)
    {
        $this->authorizeManage($request, $document);

        $document->claims()->detach($claim->id);
        return back()->with('status', 'Claim removed from document.');
    }

    /**
     * Neither store() nor destroy() had a server-side ownership check before -
     * documents/show.blade.php only ever renders the insert/remove UI for $isOwner,
     * but the endpoints themselves accepted a request from any authenticated user.
     * Documented under Security hardening.
     */
    protected function authorizeManage(Request $request, Document $document): void
    {
        abort_unless(
            $request->user()->id === $document->owner_id || $request->user()->can('access-admin'),
            403
        );
    }
}
