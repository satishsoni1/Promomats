<?php

namespace App\Http\Controllers;

use App\Models\DocumentWorkflowInstance;
use App\Services\Audit\AuditLogger;
use App\Services\DocumentVersionService;
use App\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DocumentApprovalController extends Controller
{
    public function __construct(
        protected WorkflowEngine $engine,
        protected DocumentVersionService $versions,
        protected AuditLogger $audit,
    ) {}

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

        // Offline sign-off (the Chairperson): the Content Manager holding the stage
        // uploads the copy signed / commented offline and records its outcome.
        $myStage = $instance->pendingAssignees()->where('user_id', $request->user()->id)->first()?->stage;
        $isOffline = (bool) $myStage?->isOffline();

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,approved_with_changes,not_approved'],
            'comments' => ['nullable', 'string', 'max:5000'],
            'password' => [$requirePassword ? 'required' : 'nullable', 'string'],
            'signed_copy' => [$isOffline && $request->input('decision') !== 'not_approved' ? 'required' : 'nullable', 'file', 'max:512000'],
        ], [
            'signed_copy.required' => 'Upload the copy approved by the Chairperson to record this outcome.',
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

        if ($isOffline && $request->hasFile('signed_copy')) {
            $document = $instance->document;
            $document->assertNotOnLegalHold();
            $version = $this->versions->storeNewVersion(
                document: $document,
                file: $request->file('signed_copy'),
                uploader: $request->user(),
                changeNotes: "{$myStage->name}: copy received offline, uploaded by {$request->user()->name}",
            );
            $this->audit->record(action: 'VERSION_CREATED', document: $document, version: $version, actor: $request->user(), description: 'Offline sign-off copy uploaded.');
            // The signed record points at the copy that was actually signed off.
            $this->engine->adoptNewVersion($instance, $version);
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
