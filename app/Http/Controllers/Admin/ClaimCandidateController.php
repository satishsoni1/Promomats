<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\ClaimCandidate;
use Illuminate\Http\Request;

class ClaimCandidateController extends Controller
{
    /**
     * Human review queue for AI-extracted claim candidates (REQ-3.1). Nothing here
     * touches the real Claims library until an admin explicitly accepts a row.
     */
    public function index(Request $request)
    {
        $status = $request->get('status', 'pending');

        $candidates = ClaimCandidate::with(['document', 'requestedBy', 'reviewedBy', 'createdClaim'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $counts = [
            'pending' => ClaimCandidate::where('status', 'pending')->count(),
            'accepted' => ClaimCandidate::where('status', 'accepted')->count(),
            'rejected' => ClaimCandidate::where('status', 'rejected')->count(),
        ];

        return view('admin.claim-candidates.index', compact('candidates', 'status', 'counts'));
    }

    /**
     * Accepting turns the candidate into a real Claim (created in draft status, so
     * it still goes through the normal claim-approval expectations) and links it
     * back for traceability.
     */
    public function accept(Request $request, ClaimCandidate $candidate)
    {
        abort_unless($candidate->status === ClaimCandidate::STATUS_PENDING, 422, 'This candidate has already been reviewed.');

        $claim = Claim::create([
            'match_text' => $candidate->suggested_text,
            'category' => $candidate->suggested_category,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        $candidate->update([
            'status' => ClaimCandidate::STATUS_ACCEPTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'created_claim_id' => $claim->id,
        ]);

        return back()->with('status', 'Accepted — a new draft claim was created in the library.');
    }

    public function reject(Request $request, ClaimCandidate $candidate)
    {
        abort_unless($candidate->status === ClaimCandidate::STATUS_PENDING, 422, 'This candidate has already been reviewed.');

        $candidate->update([
            'status' => ClaimCandidate::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Candidate rejected.');
    }
}
