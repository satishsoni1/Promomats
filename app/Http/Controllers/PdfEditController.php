<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\PdfEdit;
use App\Notifications\DocumentActionNotification;
use App\Services\DocumentVersionService;
use Illuminate\Http\Request;

class PdfEditController extends Controller
{
    public function __construct(protected DocumentVersionService $versionService) {}

    public function edit(Request $request, Document $document)
    {
        abort_unless(
            $request->user()->id === $document->owner_id || $request->user()->can('access-admin'),
            403
        );
        abort_unless($document->currentVersion?->isPdf(), 422, 'Only PDF documents can be edited this way.');
        abort_if($document->legal_hold, 423, "This document is under legal hold and cannot be edited. Reason: {$document->legal_hold_reason}");

        return view('documents.pdf-editor', compact('document'));
    }

    /**
     * Real in-place PDF content editing (add text / redact), performed client-side
     * with pdf-lib (resources/js/pdf-editor.js) - the browser mutates a copy of the
     * PDF's actual bytes and uploads the result here as a completely normal new
     * document version, through the exact same DocumentVersionService every other
     * version upload uses. Alongside it, this stores the structured edit log
     * (edit_type/page/position/content, one row per edit) tied to that new version,
     * with the acting user attributed server-side (never trust the client for who
     * made an edit) - this is what the document page's "Content Edits" panel reads
     * to show every edit in the approval flow.
     */
    public function store(Request $request, Document $document)
    {
        abort_unless(
            $request->user()->id === $document->owner_id || $request->user()->can('access-admin'),
            403
        );
        $document->assertNotOnLegalHold();

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:512000'],
            'change_notes' => ['nullable', 'string', 'max:2000'],
            'edits' => ['required', 'array', 'min:1'],
            'edits.*.edit_type' => ['required', 'in:add_text,redact'],
            'edits.*.page_number' => ['required', 'integer', 'min:1'],
            'edits.*.x' => ['required', 'numeric', 'min:0', 'max:100'],
            'edits.*.y' => ['required', 'numeric', 'min:0', 'max:100'],
            'edits.*.width' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'edits.*.height' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'edits.*.content' => ['nullable', 'string', 'max:1000'],
        ]);

        $version = $this->versionService->storeNewVersion(
            document: $document,
            file: $request->file('file'),
            uploader: $request->user(),
            changeNotes: $validated['change_notes'] ?: $this->summarize($validated['edits']),
        );

        foreach ($validated['edits'] as $edit) {
            PdfEdit::create([
                'document_version_id' => $version->id,
                'actor_id' => $request->user()->id,
                'edit_type' => $edit['edit_type'],
                'page_number' => $edit['page_number'],
                'x' => $edit['x'],
                'y' => $edit['y'],
                'width' => $edit['width'] ?? null,
                'height' => $edit['height'] ?? null,
                'content' => $edit['content'] ?? null,
            ]);
        }

        $this->notifyStakeholders($document, $request->user(), count($validated['edits']));

        return back()->with('status', 'Edited PDF saved as a new version — ' . count($validated['edits']) . ' content edit(s) recorded.');
    }

    protected function summarize(array $edits): string
    {
        $additions = collect($edits)->where('edit_type', 'add_text')->count();
        $redactions = collect($edits)->where('edit_type', 'redact')->count();

        $parts = array_filter([
            $additions ? "{$additions} addition(s)" : null,
            $redactions ? "{$redactions} redaction(s)" : null,
        ]);

        return 'Content edited: ' . implode(', ', $parts) . '.';
    }

    protected function notifyStakeholders(Document $document, $actor, int $editCount): void
    {
        $recipientIds = collect([$document->owner_id])
            ->merge($document->watchers()->pluck('users.id'))
            ->merge($document->activeWorkflowInstance?->pendingAssignees()->pluck('user_id') ?? collect())
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $actor->id)
            ->values();

        $users = \App\Models\User::whereIn('id', $recipientIds)->where('is_active', true)->get();

        foreach ($users as $user) {
            $user->notify(new DocumentActionNotification(
                document: $document,
                event: 'content_edited',
                actor: $actor,
                comments: "{$editCount} content edit(s) made directly in the PDF.",
            ));
        }
    }
}
