<?php

namespace App\Http\Controllers;

use App\Events\DocumentDistributed;
use App\Models\Document;
use Illuminate\Http\Request;

class DistributionController extends Controller
{
    /**
     * Confirms the pending distribution_records row WorkflowEngine::completeInstance()
     * created (via the DocumentFinalApproved listener) when the document reached
     * approved_for_distribution - the "it actually went out" event, separate from
     * the document reaching that status.
     */
    public function confirm(Request $request, Document $document)
    {
        $this->authorize('manageDistribution', $document);

        $record = $document->distributionRecords()->where('status', 'pending')->firstOrFail();

        $validated = $request->validate([
            'distribution_channel' => ['required', 'string', 'max:255'],
            'distribution_date' => ['required', 'date'],
        ]);

        $record->update([
            'distribution_channel' => $validated['distribution_channel'],
            'distribution_date' => $validated['distribution_date'],
            'distributed_by' => $request->user()->id,
            'status' => 'distributed',
        ]);

        DocumentDistributed::dispatch($record->fresh(), $request->user());

        return back()->with('status', 'Distribution confirmed.');
    }
}
