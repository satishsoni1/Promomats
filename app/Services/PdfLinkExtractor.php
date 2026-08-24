<?php

namespace App\Services;

use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * REQ-2.4: reads a PDF's own embedded Link annotation objects (the /Annots -> /A ->
 * /URI structure in the raw PDF object graph) rather than its plain text - a
 * genuinely different, structured layer that DocumentTextExtractor's text-only
 * parsing doesn't touch. This is the piece the PRD flagged as highest technical
 * uncertainty; proven against a hand-built test PDF containing a real Link
 * annotation before being wired into the app (smalot/pdfparser has no built-in
 * "get me the links" API, but Document::getPages() + Page::getHeader()->get('Annots')
 * exposes the raw annotation objects, which is what this walks).
 */
class PdfLinkExtractor
{
    /**
     * @return array<int, array{page_number: int, uri: string, rect: ?string}>
     */
    public function extract(DocumentVersion $version): array
    {
        if (! $version->isPdf()) {
            return [];
        }

        try {
            $disk = Storage::disk($version->disk);
            if (! $disk->exists($version->file_path)) {
                return [];
            }

            $parser = new PdfParser();
            $pdf = $parser->parseContent($disk->get($version->file_path));
            $pages = $pdf->getPages();
        } catch (\Throwable $e) {
            // Encrypted/malformed/scanned-image PDFs - not fatal, just no links found.
            return [];
        }

        $links = [];

        foreach ($pages as $index => $page) {
            try {
                $header = $page->getHeader();
                if (! $header->has('Annots')) {
                    continue;
                }

                $annots = $header->get('Annots');
                if (! method_exists($annots, 'getContent')) {
                    continue;
                }

                foreach ($annots->getContent() as $annot) {
                    if (! method_exists($annot, 'getHeader')) {
                        continue;
                    }

                    $annotHeader = $annot->getHeader();
                    if ((string) $annotHeader->get('Subtype') !== 'Link' || ! $annotHeader->has('A')) {
                        continue;
                    }

                    $action = $annotHeader->get('A');
                    if (! method_exists($action, 'has') || ! $action->has('URI')) {
                        continue; // internal /Dest jumps (to another page) aren't reference links
                    }

                    $uri = trim((string) $action->get('URI'));
                    if ($uri === '') {
                        continue;
                    }

                    $links[] = [
                        'page_number' => $index + 1,
                        'uri' => mb_substr($uri, 0, 2048),
                        'rect' => $annotHeader->has('Rect') ? (string) $annotHeader->get('Rect') : null,
                    ];
                }
            } catch (\Throwable $e) {
                continue; // one malformed annotation/page shouldn't sink the whole extraction
            }
        }

        return $links;
    }
}
