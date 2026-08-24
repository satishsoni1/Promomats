<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RetrievalRequest;
use App\Services\RetrievalService;
use Illuminate\Http\Request;

class RetrievalRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status', 'all');

        $requests = RetrievalRequest::with(['document', 'requestedBy'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('requested_at')
            ->paginate(25)
            ->withQueryString();

        $counts = [
            'pending' => RetrievalRequest::where('status', 'pending')->count(),
            'in_progress' => RetrievalRequest::where('status', 'in_progress')->count(),
            'completed' => RetrievalRequest::where('status', 'completed')->count(),
            'failed' => RetrievalRequest::where('status', 'failed')->count(),
        ];

        return view('admin.retrieval-requests.index', compact('requests', 'status', 'counts'));
    }

    /**
     * Manual override for the scheduled job below - runs the restore immediately,
     * useful for demos and for jumping the queue on an urgent request.
     */
    public function process(RetrievalRequest $retrievalRequest, RetrievalService $service)
    {
        abort_unless(in_array($retrievalRequest->status, ['pending', 'in_progress']), 422, 'This request has already finished.');

        $service->restore($retrievalRequest);

        return back()->with('status', 'Retrieval processed — see status below.');
    }
}
