<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Document;
use App\Services\AiService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(protected AiService $ai) {}

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $documents = collect();
        $claims = collect();

        if ($q !== '') {
            $documents = Document::with(['owner', 'currentVersion'])
                ->where(function ($query) use ($q) {
                    $query->where('title', 'like', "%{$q}%")
                        ->orWhere('reference_no', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%")
                        ->orWhere('category', 'like', "%{$q}%");
                })
                ->latest()
                ->limit(25)
                ->get();

            $claims = Claim::where('match_text', 'like', "%{$q}%")
                ->orWhere('category', 'like', "%{$q}%")
                ->orWhere('product', 'like', "%{$q}%")
                ->limit(25)
                ->get();
        }

        return view('search.index', compact('q', 'documents', 'claims'))
            ->with('aiAvailable', $this->ai->isAvailable());
    }

    /**
     * Natural-language search: "documents mentioning cardiovascular risk expiring
     * this quarter" instead of manual filters. Feeds a compact catalog of documents
     * to the model and asks it to pick + explain the matches, rather than trying to
     * translate the question into SQL filters up front - simpler and copes with
     * vague/compound questions a filter form can't.
     */
    public function ai(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        abort_unless($this->ai->isAvailable(), 422, 'AI is not configured. Ask an admin to add a key at Admin > AI Settings.');

        $results = collect();

        if ($q !== '') {
            $catalog = Document::query()
                ->latest()
                ->limit(300)
                ->get(['id', 'title', 'description', 'category', 'status', 'products', 'countries', 'expiry_date', 'created_at'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'title' => $d->title,
                    'description' => str($d->description)->limit(200)->toString(),
                    'category' => $d->category,
                    'status' => $d->status,
                    'products' => $d->products,
                    'countries' => $d->countries,
                    'expiry_date' => $d->expiry_date?->toDateString(),
                ]);

            try {
                $raw = $this->ai->chat(
                    systemPrompt: 'You search a pharma document library on behalf of a user. You only ever reference '
                        . 'documents from the exact JSON catalog given to you - never invent one. Today\'s date is ' . now()->toDateString() . '.',
                    userPrompt: "User's question: \"{$q}\"\n\nDocument catalog (JSON):\n" . $catalog->toJson()
                        . "\n\nReturn ONLY a JSON array of matches, each shaped exactly like "
                        . "{\"id\": <document id>, \"reason\": \"<one short sentence why it matches>\"}. "
                        . 'Order by relevance. Return [] if nothing matches. Max 15 results.',
                    maxTokens: 1500,
                );
            } catch (\Throwable $e) {
                return back()->withErrors(['ai' => 'AI search failed: ' . $e->getMessage()]);
            }

            $matches = $this->parseMatches($raw);
            $documentsById = Document::with(['owner', 'currentVersion'])->whereIn('id', array_column($matches, 'id'))->get()->keyBy('id');

            $results = collect($matches)
                ->map(fn ($m) => isset($documentsById[$m['id']]) ? ['document' => $documentsById[$m['id']], 'reason' => $m['reason']] : null)
                ->filter()
                ->values();
        }

        return view('search.ai', compact('q', 'results'));
    }

    protected function parseMatches(string $raw): array
    {
        $json = preg_match('/\[.*\]/s', $raw, $m) ? $m[0] : $raw;
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
