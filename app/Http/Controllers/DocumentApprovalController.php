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
     * Record the signed-in user's decision. recordDecision() stores the signer's
     * printed name, time and IP as it stood at that instant.
     *
     * Re-entering the password at the moment of signing (the 21 CFR Part 11 "act of
     * signing") is optional - config('promomats.approvals.require_password'). It is
     * off by default because users are already signed in and were being asked for
     * a password they had already given (UAT feedback).
     */
    public function act(Request $request, DocumentWorkflowInstance $instance)
    {
        $requirePassword = config('promomats.approvals.require_password', false);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,approved_with_changes,not_approved'],
            'comments' => ['nullable', 'string', 'max:5000'],
            'password' => [$requirePassword ? 'required' : 'nullable', 'string'],
        ]);

        if ($requirePassword && ! Hash::check($validated['password'], $request->user()->password)) {
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
