<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\PdfLink;
use App\Services\PdfLinkExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PdfLinkController extends Controller
{
    /**
     * REQ-2.4: extracts embedded hyperlinks from the document's current PDF version
     * and attempts a lightweight filename/title match against its own reference
     * attachments (REQ-2.3). Safe to re-run after a new version upload - existing
     * rows for this exact version+page+uri are left alone rather than duplicated.
     */
    public function extract(Document $document, PdfLinkExtractor $extractor)
    {
        $version = $document->currentVersion;
        abort_unless($version && $version->isPdf(), 422, 'The current version is not a PDF.');

        $found = $extractor->extract($version);

        if (empty($found)) {
            return back()->with('status', 'No embedded links were found in this PDF.');
        }

        $references = $document->referenceAttachments;
        $created = 0;

        foreach ($found as $row) {
            $exists = PdfLink::where('document_version_id', $version->id)
                ->where('page_number', $row['page_number'])
                ->where('uri', $row['uri'])
                ->exists();
            if ($exists) {
                continue;
            }

            $match = $this->heuristicMatch($row['uri'], $references);

            PdfLink::create([
                'document_version_id' => $version->id,
                'page_number' => $row['page_number'],
                'uri' => $row['uri'],
                'rect' => $row['rect'],
                'matched_reference_attachment_id' => $match?->id,
                'status' => $match ? PdfLink::STATUS_MATCHED : PdfLink::STATUS_UNMATCHED,
            ]);
            $created++;
        }

        return back()->with('status', "Found " . count($found) . " link(s) in the PDF — {$created} new, " . (count($found) - $created) . ' already known.');
    }

    /**
     * Manually connect a link to one of the document's reference attachments -
     * covers whatever the filename heuristic in extract() didn't catch.
     */
    public function link(Request $request, Document $document, PdfLink $pdfLink)
    {
        $this->authorizeLink($document, $pdfLink);

        $validated = $request->validate([
            'reference_attachment_id' => ['required', 'exists:reference_attachments,id'],
        ]);

        abort_unless(
            $document->referenceAttachments->contains('id', (int) $validated['reference_attachment_id']),
            422,
            'That reference is not attached to this document.'
        );

        $pdfLink->update([
            'matched_reference_attachment_id' => $validated['reference_attachment_id'],
            'status' => PdfLink::STATUS_MATCHED,
        ]);

        return back()->with('status', 'Link connected to reference.');
    }

    public function unlink(Document $document, PdfLink $pdfLink)
    {
        $this->authorizeLink($document, $pdfLink);

        $pdfLink->update(['matched_reference_attachment_id' => null, 'status' => PdfLink::STATUS_UNMATCHED]);

        return back()->with('status', 'Link disconnected.');
    }

    public function ignore(Document $document, PdfLink $pdfLink)
    {
        $this->authorizeLink($document, $pdfLink);

        $pdfLink->update(['matched_reference_attachment_id' => null, 'status' => PdfLink::STATUS_IGNORED]);

        return back()->with('status', 'Link marked as not a reference.');
    }

    protected function authorizeLink(Document $document, PdfLink $pdfLink): void
    {
        abort_unless($pdfLink->documentVersion?->document_id === $document->id, 404);
    }

    /**
     * Matches a link's URI against the document's own reference attachments by
     * filename/title - e.g. a link ending in ".../vx204-study.pdf" against a
     * reference file also named "vx204-study.pdf", or a URI containing a reference's
     * title as a substring. Deliberately simple (no AI call) since this runs on every
     * extraction; anything it misses is still just one click away via the manual
     * "Link to reference" picker in the UI.
     */
    protected function heuristicMatch(string $uri, $references)
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $segment = $path ? strtolower(basename($path)) : '';
        $uriLower = strtolower($uri);

        return $references->first(function ($reference) use ($segment, $uriLower) {
            $filename = strtolower($reference->original_filename);
            $title = strtolower($reference->title);

            return ($segment !== '' && $segment === $filename)
                || ($filename !== '' && str_contains($uriLower, $filename))
                || (Str::length($title) >= 6 && str_contains($uriLower, Str::slug($title, '-')));
        });
    }
}
