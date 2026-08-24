<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    public function index(Request $request)
    {
        $claims = Claim::withCount('documents')
            ->when($request->filled('search'), fn ($q) => $q->where('match_text', 'like', "%{$request->search}%"))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->latest()
            ->paginate(25);

        return view('admin.claims.index', compact('claims'));
    }

    public function create()
    {
        return view('admin.claims.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'match_text' => ['required', 'string', 'max:500'],
            'body' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:120'],
            'product' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'products' => ['nullable', 'string', 'max:500'],
            'countries' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:draft,approved,expired'],
            'reference_title.*' => ['nullable', 'string', 'max:255'],
            'reference_citation.*' => ['nullable', 'string'],
            'reference_url.*' => ['nullable', 'url'],
        ]);

        $claim = Claim::create([
            ...$validated,
            'products' => $this->splitTags($validated['products'] ?? null),
            'countries' => $this->splitTags($validated['countries'] ?? null),
            'created_by' => $request->user()->id,
            'approved_by' => $validated['status'] === 'approved' ? $request->user()->id : null,
            'approved_at' => $validated['status'] === 'approved' ? now() : null,
        ]);

        $this->syncReferences($request, $claim);

        return redirect()->route('admin.claims.index')->with('status', 'Claim created.');
    }

    public function show(Claim $claim)
    {
        $claim->load(['references', 'documents.owner', 'contentModules']);
        return view('admin.claims.show', compact('claim'));
    }

    public function edit(Claim $claim)
    {
        $claim->load(['references', 'referenceAttachments.uploader']);
        return view('admin.claims.edit', compact('claim'));
    }

    public function update(Request $request, Claim $claim)
    {
        $validated = $request->validate([
            'match_text' => ['required', 'string', 'max:500'],
            'body' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:120'],
            'product' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'products' => ['nullable', 'string', 'max:500'],
            'countries' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:draft,approved,expired'],
        ]);

        $wasApproved = $claim->status === 'approved';

        $claim->update([
            ...$validated,
            'products' => $this->splitTags($validated['products'] ?? null),
            'countries' => $this->splitTags($validated['countries'] ?? null),
            'approved_by' => ! $wasApproved && $validated['status'] === 'approved' ? $request->user()->id : $claim->approved_by,
            'approved_at' => ! $wasApproved && $validated['status'] === 'approved' ? now() : $claim->approved_at,
        ]);

        $claim->references()->delete();
        $this->syncReferences($request, $claim);

        return redirect()->route('admin.claims.edit', $claim)->with('status', 'Claim updated.');
    }

    public function destroy(Claim $claim)
    {
        if ($claim->documents()->exists()) {
            return back()->withErrors(['claim' => 'Cannot delete a claim that is currently used in one or more documents.']);
        }

        $claim->delete();
        return redirect()->route('admin.claims.index')->with('status', 'Claim deleted.');
    }

    protected function splitTags(?string $raw): ?array
    {
        if (blank($raw)) {
            return null;
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return $tags ?: null;
    }

    protected function syncReferences(Request $request, Claim $claim): void
    {
        $titles = $request->input('reference_title', []);

        foreach ($titles as $i => $title) {
            if (blank($title)) {
                continue;
            }

            $claim->references()->create([
                'title' => $title,
                'citation' => $request->input('reference_citation.' . $i),
                'url' => $request->input('reference_url.' . $i),
            ]);
        }
    }
}
