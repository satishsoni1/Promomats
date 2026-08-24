<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\RetrievalRequest;
use App\Models\User;
use App\Notifications\DocumentActionNotification;
use Illuminate\Http\Request;

class RetrievalRequestController extends Controller
{
    /**
     * REQ-4.3: the document owner (or an admin) asks for a document's cold-stored
     * files to be brought back onto primary storage. Creates the request with a
     * 24-hour SLA and notifies admins - actual restoration happens via the admin's
     * "Process Now" action or the scheduled documents:process-retrieval-requests job.
     */
    public function store(Request $request, Document $document)
    {
        abort_unless(
            $request->user()->id === $document->owner_id || $request->user()->can('access-admin'),
            403
        );

        if (! $document->isOnColdStorage()) {
            return back()->withErrors(['retrieval' => 'This document has no files in cold storage to retrieve.']);
        }

        $hasOpenRequest = $document->retrievalRequests()
            ->whereIn('status', [RetrievalRequest::STATUS_PENDING, RetrievalRequest::STATUS_IN_PROGRESS])
            ->exists();

        if ($hasOpenRequest) {
            return back()->withErrors(['retrieval' => 'A retrieval request for this document is already in progress.']);
        }

        $retrievalRequest = RetrievalRequest::create([
            'document_id' => $document->id,
            'requested_by' => $request->user()->id,
            'status' => RetrievalRequest::STATUS_PENDING,
            'requested_at' => now(),
            'sla_due_at' => now()->addHours(RetrievalRequest::SLA_HOURS),
        ]);

        $admins = User::whereHas('roles', fn ($q) => $q->where('slug', 'admin'))->where('is_active', true)->get();
        foreach ($admins as $admin) {
            $admin->notify(new DocumentActionNotification(
                document: $document,
                event: 'retrieval_requested',
                actor: $request->user(),
            ));
        }

        return back()->with('status', "Retrieval requested — target completion within {$retrievalRequest->sla_due_at->diffForHumans(now(), true)}.");
    }
}
