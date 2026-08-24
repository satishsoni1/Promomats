<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Pulls the best available text out of a document for AI features to read: the
 * actual PDF body text when the current version is a PDF, otherwise falls back to
 * the document's own metadata (title/description/category/change notes) plus any
 * claims already linked to it. Capped to keep prompts fast and affordable.
 */
class DocumentTextExtractor
{
    protected const MAX_CHARS = 12000;

    public function extract(Document $document): string
    {
        $version = $document->currentVersion;
        $body = '';

        if ($version && $version->isPdf()) {
            $body = $this->extractPdfText($version);
        }

        $metadata = collect([
            "Title: {$document->title}",
            $document->description ? "Description: {$document->description}" : null,
            $document->category ? "Category: {$document->category}" : null,
            $document->products ? 'Product(s): ' . implode(', ', $document->products) : null,
            $document->countries ? 'Countries(s): ' . implode(', ', $document->countries) : null,
            $version?->change_notes ? "Latest version notes: {$version->change_notes}" : null,
        ])->filter()->implode("\n");

        $claimsText = $document->claims->isNotEmpty()
            ? "\n\nClaims already inserted into this document:\n" . $document->claims->map(fn ($c) => "- {$c->match_text}")->implode("\n")
            : '';

        $full = $metadata . $claimsText . ($body ? "\n\nDocument text:\n{$body}" : "\n\n(No extractable text body - this file type isn't text-parseable, so only the metadata above is available.)");

        return mb_substr($full, 0, self::MAX_CHARS);
    }

    protected function extractPdfText($version): string
    {
        try {
            $disk = Storage::disk($version->disk);
            if (! $disk->exists($version->file_path)) {
                return '';
            }

            $parser = new PdfParser();
            $pdf = $parser->parseContent($disk->get($version->file_path));

            return trim($pdf->getText());
        } catch (\Throwable $e) {
            // Encrypted/malformed/scanned-image PDFs can't be parsed this way - not fatal,
            // the caller just falls back to metadata-only.
            return '';
        }
    }
}
