<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentActionNotification;
use App\Services\Audit\AuditLogger;
use App\Services\DocumentVersionService;
use Illuminate\Http\Request;

/**
 * In-browser Word review with SuperDoc (resources/js/word-editor.js): the .docx is
 * edited entirely client-side - tracked changes and Word comments included - and
 * the browser uploads the exported .docx here as a completely normal new version,
 * replacing the old download -> mark up in Word -> re-upload loop. No document
 * server is involved, so there's no per-editor cap.
 */
class WordReviewController extends Controller
{
    public function __construct(
        protected DocumentVersionService $versionService,
        protected AuditLogger $audit,
    ) {}

    /**
     * What the editor lets this user do:
     *  - legal hold, or a reviewer who has already submitted their review while
     *    the job is with someone else: read-only (tracked changes and comments shown)
     *  - the Brand Manager who owns the job (and admins): editor - besides
     *    suggesting, they can accept / reject the stakeholders' tracked changes
     *  - every other stakeholder: suggester - each edit is a tracked change, plus
     *    comments and highlight annotations
     * The editor enforces this client-side; store() re-checks what it can.
     */
    public static function editorRole(Document $document, User $user): string
    {
        // Agency logins read the job (with its comments) but upload through their task.
        if ($document->legal_hold || $document->commentsLockedFor($user) || $user->isAgencyUser()) {
            return 'viewer';
        }

        if ($user->id === $document->owner_id || $user->can('access-admin')) {
            return 'editor';
        }

        return 'suggester';
    }

    public function store(Request $request, Document $document)
    {
        $this->authorize('view', $document);
        $document->assertNotOnLegalHold();
        abort_if($document->commentsLockedFor($request->user()), 403, 'You have already submitted your review of this job.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:docx', 'max:512000'],
            'base_version_id' => ['required', 'integer'],
            'change_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Someone uploaded a newer version while this reviewer had the document
        // open - saving would silently bury that version's changes under theirs.
        if ((int) $validated['base_version_id'] !== (int) $document->current_version_id) {
            return response()->json([
                'message' => 'A newer version of this document was uploaded while you were reviewing. Download your copy from the editor so nothing is lost, then reload to review the latest version.',
            ], 409);
        }

        $user = $request->user();
        $notes = trim(($validated['change_notes'] ?? '') ?: 'Tracked changes/comments suggested in the browser by ' . $user->name);

        $version = $this->versionService->storeNewVersion(
            document: $document,
            file: $request->file('file'),
            uploader: $user,
            changeNotes: $notes,
        );

        $this->audit->record(action: 'VERSION_CREATED', document: $document, version: $version, actor: $user, description: $notes);
        $this->notifyStakeholders($document, $user);

        return response()->json([
            'version_id' => $version->id,
            'version_no' => $version->version_no,
        ]);
    }

    protected function notifyStakeholders(Document $document, User $actor): void
    {
        $recipientIds = collect([$document->owner_id])
            ->merge($document->watchers()->pluck('users.id'))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $actor->id)
            ->values();

        User::whereIn('id', $recipientIds)->where('is_active', true)->get()
            ->each(fn (User $user) => $user->notify(new DocumentActionNotification(
                document: $document,
                event: 'version_uploaded',
                actor: $actor,
            )));
    }
}
