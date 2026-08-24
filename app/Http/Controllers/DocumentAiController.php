<?php

namespace App\Http\Controllers;

use App\Models\AiInsight;
use App\Models\Claim;
use App\Models\ClaimCandidate;
use App\Models\Document;
use App\Models\DocumentApprovalAction;
use App\Models\ReferenceAttachment;
use App\Services\AiService;
use App\Services\DocumentTextExtractor;
use Illuminate\Http\Request;

class DocumentAiController extends Controller
{
    public function __construct(
        protected AiService $ai,
        protected DocumentTextExtractor $extractor,
    ) {}

    /**
     * AI Compliance Pre-check: reads the document (PDF body if available, else its
     * metadata + linked claims) and flags likely regulatory issues before it goes
     * into human review - missing fair-balance/safety info, off-label language,
     * unsubstantiated superlatives, missing disclaimers. Result is stored permanently
     * as part of the document's audit trail, not just shown once and discarded.
     */
    public function complianceCheck(Request $request, Document $document)
    {
        abort_unless($this->ai->isAvailable(), 422, 'AI is not configured. Ask an admin to add a key at Admin > AI Settings.');

        $text = $this->extractor->extract($document);

        try {
            $raw = $this->ai->chat(
                systemPrompt: 'You are an experienced pharmaceutical regulatory/MLR (Medical, Legal, Regulatory) '
                    . 'compliance reviewer. You do a fast first-pass check on promotional material before it enters '
                    . 'the human approval workflow, to catch likely issues early - you are not the final approver.',
                userPrompt: "Review the following document for regulatory compliance issues typical of pharmaceutical "
                    . "promotional material: missing required safety/fair-balance information, off-label claims, "
                    . "unsubstantiated superlatives (\"best\", \"guaranteed\", \"cure\", \"miracle\"), missing "
                    . "disclaimers, overclaiming/tone issues, efficacy claims without a cited reference.\n\n"
                    . "Return ONLY a JSON array, each item shaped exactly like "
                    . "{\"severity\": \"low\"|\"medium\"|\"high\", \"issue\": \"...\", \"suggestion\": \"...\"}. "
                    . "Return [] if you find nothing notable. No prose outside the JSON.\n\nDOCUMENT:\n{$text}",
                maxTokens: 1500,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['ai' => 'AI compliance check failed: ' . $e->getMessage()]);
        }

        $findings = $this->parseFindings($raw);
        $riskLevel = $this->highestSeverity($findings);

        $insight = AiInsight::create([
            'document_id' => $document->id,
            'requested_by' => $request->user()->id,
            'type' => 'compliance_check',
            'model' => config('services.ai_model_used', 'claude'),
            'output' => json_encode($findings, JSON_PRETTY_PRINT),
            'risk_level' => $riskLevel,
        ]);

        return back()->with('ai_compliance_result', $insight->id);
    }

    /**
     * AI claim suggestions: an on-demand upgrade over the always-on substring
     * heuristic already on the document page - sends the approved claims library to
     * the model and asks it to reason about genuine relevance (catches paraphrases
     * the substring match misses), rather than needing a separate embeddings index.
     */
    public function suggestClaims(Request $request, Document $document)
    {
        abort_unless($this->ai->isAvailable(), 422, 'AI is not configured. Ask an admin to add a key at Admin > AI Settings.');

        $linkedIds = $document->claims->pluck('id');
        $candidates = Claim::where('status', 'approved')->whereNotIn('id', $linkedIds)->get(['id', 'match_text', 'category', 'product']);

        if ($candidates->isEmpty()) {
            return back()->with('status', 'No approved claims available to suggest.');
        }

        $catalog = $candidates->map(fn ($c) => "#{$c->id}: \"{$c->match_text}\"" . ($c->category ? " ({$c->category})" : ''))->implode("\n");

        try {
            $raw = $this->ai->chat(
                systemPrompt: 'You help pharma content teams find relevant pre-approved claims to include in a document. '
                    . 'You only ever recommend claims from the exact numbered list given to you - never invent one.',
                userPrompt: "Document title: {$document->title}\nDescription: {$document->description}\nCategory: {$document->category}\n"
                    . "Product(s): " . implode(', ', $document->products ?? []) . "\n\n"
                    . "Candidate claims (numbered):\n{$catalog}\n\n"
                    . "Return ONLY a JSON array of the IDs (numbers only, from the # values above) of claims that are "
                    . "genuinely relevant to this document, most relevant first. Max 5. Return [] if none are relevant.",
                maxTokens: 300,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['ai' => 'AI claim suggestion failed: ' . $e->getMessage()]);
        }

        $ids = $this->parseIdArray($raw);
        $suggested = $candidates->whereIn('id', $ids)->sortBy(fn ($c) => array_search($c->id, $ids))->values();

        return back()->with('ai_claim_suggestions', $suggested->map(fn ($c) => ['id' => $c->id, 'match_text' => $c->match_text])->all());
    }

    /**
     * AI claim extraction (REQ-3.1): reads the document body and proposes NEW
     * claim-like statements that are not yet in the claims library, as opposed to
     * suggestClaims() above which only ever recommends EXISTING approved claims.
     * Every suggestion lands in claim_candidates with status=pending - nothing is
     * written to the real Claim library until an admin explicitly accepts it.
     */
    public function extractClaims(Request $request, Document $document)
    {
        abort_unless($this->ai->isAvailable(), 422, 'AI is not configured. Ask an admin to add a key at Admin > AI Settings.');

        $text = $this->extractor->extract($document);

        if (blank(trim($text))) {
            return back()->withErrors(['ai' => 'No extractable text was found in this document to analyze.']);
        }

        $existingTexts = Claim::pluck('match_text')
            ->merge($document->claimCandidates()->where('status', '!=', 'rejected')->pluck('suggested_text'))
            ->implode(' | ');

        try {
            $raw = $this->ai->chat(
                systemPrompt: 'You are a pharmaceutical MLR content analyst. You read promotional material and identify '
                    . 'specific, substantiable statements about a product\'s efficacy, safety, mechanism, or benefit that '
                    . 'could become reusable, pre-approved "claims" in a claims library - as distinct from generic '
                    . 'marketing copy, headers, or disclaimers.',
                userPrompt: "Read the following document and propose up to 8 candidate claims: specific factual or "
                    . "efficacy/safety statements worth adding to a reusable claims library. Do NOT propose anything "
                    . "that duplicates or closely paraphrases one of these already-known claims:\n{$existingTexts}\n\n"
                    . "Return ONLY a JSON array, each item shaped exactly like "
                    . "{\"text\": \"...\", \"category\": \"efficacy\"|\"safety\"|\"mechanism\"|\"other\", \"confidence\": 0.0-1.0}. "
                    . "Return [] if nothing qualifies. No prose outside the JSON.\n\nDOCUMENT:\n{$text}",
                maxTokens: 1500,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['ai' => 'AI claim extraction failed: ' . $e->getMessage()]);
        }

        $proposals = $this->parseClaimProposals($raw);
        $created = 0;

        foreach ($proposals as $proposal) {
            $text = trim((string) ($proposal['text'] ?? ''));
            if (blank($text)) {
                continue;
            }

            $alreadyQueued = $document->claimCandidates()
                ->where('status', 'pending')
                ->where('suggested_text', $text)
                ->exists();
            if ($alreadyQueued) {
                continue;
            }

            ClaimCandidate::create([
                'document_id' => $document->id,
                'suggested_text' => \Illuminate\Support\Str::limit($text, 995, ''),
                'suggested_category' => $proposal['category'] ?? null,
                'ai_confidence' => is_numeric($proposal['confidence'] ?? null) ? min(1, max(0, (float) $proposal['confidence'])) : null,
                'status' => ClaimCandidate::STATUS_PENDING,
                'requested_by' => $request->user()->id,
            ]);
            $created++;
        }

        return back()->with(
            'status',
            $created > 0
                ? "AI proposed {$created} new claim candidate(s), sent to Admin > Claim Candidates for review."
                : 'The AI did not find any new candidate claims beyond what is already in the library or queued.'
        );
    }

    /**
     * AI reference suggestions (REQ-3.2): matches this document's content against
     * reference files already in the system (attached to any document or claim) and
     * proposes ones that are likely relevant here too. Purely a discovery aid -
     * nothing is attached until the user clicks "+ Attach" on a suggestion, which
     * hits ReferenceAttachmentController::attachExisting().
     */
    public function suggestReferences(Request $request, Document $document)
    {
        abort_unless($this->ai->isAvailable(), 422, 'AI is not configured. Ask an admin to add a key at Admin > AI Settings.');

        $alreadyAttachedIds = $document->referenceAttachments->pluck('id');

        $candidates = ReferenceAttachment::with('attachable')
            ->whereNotIn('id', $alreadyAttachedIds)
            ->latest()
            ->limit(60)
            ->get();

        if ($candidates->isEmpty()) {
            return back()->with('status', 'No other reference files exist yet in the system to suggest from.');
        }

        $catalog = $candidates->map(function ($r) {
            $source = $r->attachable instanceof Claim
                ? 'claim: "' . \Illuminate\Support\Str::limit($r->attachable->match_text, 60) . '"'
                : 'document: "' . ($r->attachable?->title ?? 'unknown') . '"';
            return "#{$r->id}: \"{$r->title}\" ({$r->original_filename}) - attached to {$source}";
        })->implode("\n");

        $text = $this->extractor->extract($document);

        try {
            $raw = $this->ai->chat(
                systemPrompt: 'You help pharma content teams reuse existing reference/substantiation files rather than '
                    . 'sourcing duplicates. You only ever recommend references from the exact numbered list given to '
                    . 'you - never invent one.',
                userPrompt: "This document is being prepared:\n{$text}\n\n"
                    . "Existing reference files already in the system (numbered):\n{$catalog}\n\n"
                    . "Return ONLY a JSON array of the IDs (numbers only, from the # values above) of references that "
                    . "are plausibly relevant to this document based on title/content similarity, most relevant "
                    . "first. Max 5. Return [] if none look relevant.",
                maxTokens: 300,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['ai' => 'AI reference suggestion failed: ' . $e->getMessage()]);
        }

        $ids = $this->parseIdArray($raw);
        $suggested = $candidates->whereIn('id', $ids)->sortBy(fn ($c) => array_search($c->id, $ids))->values();

        return back()->with('ai_reference_suggestions', $suggested->map(fn ($r) => [
            'id' => $r->id,
            'title' => $r->title,
            'original_filename' => $r->original_filename,
        ])->all());
    }

    /**
     * AI-drafted revision suggestion: given a specific AwC/NA decision's comment,
     * drafts concrete suggested replacement text addressing that feedback - a
     * starting point for the owner's revision, not an auto-applied edit.
     */
    public function suggestRevision(Request $request, Document $document, DocumentApprovalAction $action)
    {
        abort_unless($this->ai->isAvailable(), 422, 'AI is not configured. Ask an admin to add a key at Admin > AI Settings.');
        abort_unless($action->document_id === $document->id, 404);
        abort_if(blank($action->comments), 422, 'This decision has no reviewer comments to work from.');

        $text = $this->extractor->extract($document);

        try {
            $raw = $this->ai->chat(
                systemPrompt: 'You help pharma content owners turn reviewer feedback into a concrete revision plan. '
                    . 'You draft specific suggested wording, not vague advice - but you always note that a human must '
                    . 'still write, substantiate, and get the final text re-reviewed.',
                userPrompt: "A reviewer returned this document with the decision \"" . $action->decisionLabel() . "\" and this feedback:\n"
                    . "\"{$action->comments}\"\n\nDocument context:\n{$text}\n\n"
                    . "Draft a specific, actionable revision suggestion addressing the feedback: what to change, and "
                    . "suggested replacement wording where relevant. Keep it under 200 words. Plain text, no markdown headers.",
                maxTokens: 600,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['ai' => 'AI revision suggestion failed: ' . $e->getMessage()]);
        }

        $insight = AiInsight::create([
            'document_id' => $document->id,
            'requested_by' => $request->user()->id,
            'type' => 'revision_suggestion',
            'model' => config('services.ai_model_used', 'claude'),
            'output' => trim($raw),
        ]);

        return back()->with('ai_revision_result', $insight->id);
    }

    protected function parseFindings(string $raw): array
    {
        $json = $this->extractJson($raw);
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [['severity' => 'medium', 'issue' => 'AI response could not be parsed as structured findings.', 'suggestion' => trim($raw)]];
        }

        return $decoded;
    }

    protected function parseClaimProposals(string $raw): array
    {
        $json = $this->extractJson($raw);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function parseIdArray(string $raw): array
    {
        $json = $this->extractJson($raw);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * Models occasionally wrap JSON in prose or a markdown code fence despite
     * instructions - pull out the first [...] block rather than failing outright.
     */
    protected function extractJson(string $raw): string
    {
        if (preg_match('/\[.*\]/s', $raw, $matches)) {
            return $matches[0];
        }

        return $raw;
    }

    protected function highestSeverity(array $findings): string
    {
        $order = ['high' => 3, 'medium' => 2, 'low' => 1];
        $max = 0;

        foreach ($findings as $f) {
            $max = max($max, $order[$f['severity'] ?? 'low'] ?? 1);
        }

        return array_flip($order)[$max] ?? 'low';
    }
}
