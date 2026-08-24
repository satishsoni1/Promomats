<?php

namespace App\Http\Controllers;

use App\Models\ClaimReferenceMapping;
use App\Models\Document;
use Illuminate\Http\Request;

class ClaimReferenceMappingController extends Controller
{
    /**
     * REQ-3.3: pins a specific highlighted text span on a PDF page to a claim and/or
     * a reference attachment already associated with this document. At least one of
     * claim_id/reference_attachment_id is required - a mapping that points at
     * neither has nothing to show.
     */
    public function store(Request $request, Document $document)
    {
        $validated = $request->validate([
            'page_number' => ['required', 'integer', 'min:1'],
            'x_position' => ['required', 'numeric', 'min:0', 'max:100'],
            'y_position' => ['required', 'numeric', 'min:0', 'max:100'],
            'selected_text' => ['nullable', 'string', 'max:500'],
            'claim_id' => ['nullable', 'exists:claims,id'],
            'reference_attachment_id' => ['nullable', 'exists:reference_attachments,id'],
        ]);

        if (blank($validated['claim_id'] ?? null) && blank($validated['reference_attachment_id'] ?? null)) {
            return response()->json(['message' => 'Pick a claim and/or a reference to map this text to.'], 422);
        }

        if (filled($validated['claim_id'] ?? null)) {
            abort_unless($document->claims->contains('id', (int) $validated['claim_id']), 422, 'That claim is not used in this document.');
        }
        if (filled($validated['reference_attachment_id'] ?? null)) {
            abort_unless($document->referenceAttachments->contains('id', (int) $validated['reference_attachment_id']), 422, 'That reference is not attached to this document.');
        }

        $mapping = ClaimReferenceMapping::create([
            'document_id' => $document->id,
            'claim_id' => $validated['claim_id'] ?? null,
            'reference_attachment_id' => $validated['reference_attachment_id'] ?? null,
            'page_number' => $validated['page_number'],
            'x_position' => $validated['x_position'],
            'y_position' => $validated['y_position'],
            'selected_text' => $validated['selected_text'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $mapping->load(['claim', 'referenceAttachment', 'creator']);

        // Shaped to exactly match DocumentController::show()'s $claimReferenceMappingsByPage
        // array (see resources/js/pdf-viewer.js / pdf-viewer.blade.php) rather than
        // Eloquent's default relation serialization ("reference_attachment", no
        // download_url) - the PDF viewer's mapping popover reads the same "reference"
        // key/shape whether a pin came from the initial page load or a fresh save.
        return response()->json(['mapping' => [
            'id' => $mapping->id,
            'x_position' => $mapping->x_position,
            'y_position' => $mapping->y_position,
            'selected_text' => $mapping->selected_text,
            'claim' => $mapping->claim ? ['id' => $mapping->claim->id, 'match_text' => $mapping->claim->match_text] : null,
            'reference' => $mapping->referenceAttachment ? [
                'id' => $mapping->referenceAttachment->id,
                'title' => $mapping->referenceAttachment->title,
                'download_url' => $mapping->referenceAttachment->downloadUrl(),
            ] : null,
            'creator' => ['name' => $mapping->creator?->name],
        ]]);
    }

    public function destroy(Document $document, ClaimReferenceMapping $mapping)
    {
        abort_unless($mapping->document_id === $document->id, 404);

        $mapping->delete();

        if (request()->wantsJson()) {
            return response()->json(['status' => 'deleted']);
        }

        return back()->with('status', 'Mapping removed.');
    }
}
