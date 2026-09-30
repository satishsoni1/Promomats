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
     * What the editor lets this user do, mirroring the rest of the app:
     *  - legal hold: read-only (tracked changes and comments visible)
     *  - may upload versions (owner, admin, agency, dept editor): full editing,
     *    including accepting/rejecting others' tracked changes
     *  - everyone else (reviewers): suggesting only - every edit is a tracked
     *    change, plus comments
     * The editor enforces this client-side; store() re-checks what it can.
     */
    public static function editorRole(Document $document, User $user): string
    {
        if ($document->legal_hold) {
            return 'viewer';
        }

        return $user->can('uploadVersion', $document) ? 'editor' : 'suggester';
    }

    public function store(Request $request, Document $document)
    {
        $this->authorize('view', $document);
        $document->assertNotOnLegalHold();

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
        $role = self::editorRole($document, $user);
        $notes = trim(($validated['change_notes'] ?? '') ?: (
            $role === 'editor'
                ? 'Edited in the browser by ' . $user->name
                : 'Tracked changes/comments suggested in the browser by ' . $user->name
        ));

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
