<?php

namespace App\Http\Controllers;

use App\Models\DocumentWorkflowInstance;
use App\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DocumentApprovalController extends Controller
{
    public function __construct(protected WorkflowEngine $engine) {}

    /**
     * "My pending approvals" inbox - every document waiting on the logged-in user, across all workflows.
     */
    public function inbox(Request $request)
    {
        $pending = $request->user()->pendingApprovals()
            ->with(['instance.document', 'instance.currentStage', 'stage'])
            ->latest('assigned_at')
            ->paginate(20);

        return view('approvals.inbox', compact('pending'));
    }

    /**
     * 21 CFR Part 11 requires an electronic signature to be executed with two
     * distinct identification components - being logged in already satisfies "who
     * you are", but a signature also needs an *act of signing*, not just an
     * already-open session someone could walk up to. Re-confirming the password
     * here, at the moment of decision, is that second component; recordDecision()
     * then stores the signer's printed name as it stood at that instant.
     */
    public function act(Request $request, DocumentWorkflowInstance $instance)
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,approved_with_changes,not_approved'],
            'comments' => ['nullable', 'string', 'max:5000'],
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'Incorrect password - your decision was not recorded. Re-enter your password to sign.',
            ]);
        }

        // comments required for anything other than a clean approval - standard pharma audit practice
        if ($validated['decision'] !== 'approved' && blank($validated['comments'] ?? null)) {
            return back()->withErrors(['comments' => 'Comments are required when approving with changes or rejecting.']);
        }

        $this->engine->recordDecision(
            instance: $instance,
            actor: $request->user(),
            decision: $validated['decision'],
            comments: $validated['comments'] ?? null,
            ipAddress: $request->ip(),
        );

        return redirect()->route('documents.show', $instance->document_id)
            ->with('status', 'Your decision has been recorded and the relevant parties notified.');
    }
}
