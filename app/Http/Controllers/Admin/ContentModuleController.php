<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\ContentModule;
use Illuminate\Http\Request;

class ContentModuleController extends Controller
{
    public function index()
    {
        $modules = ContentModule::withCount('claims')->latest()->get();
        return view('admin.content-modules.index', compact('modules'));
    }

    public function create()
    {
        $claims = Claim::where('status', 'approved')->orderBy('match_text')->get();
        return view('admin.content-modules.create', compact('claims'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'product' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'claim_ids' => ['nullable', 'array'],
            'claim_ids.*' => ['exists:claims,id'],
        ]);

        $module = ContentModule::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'product' => $validated['product'] ?? null,
            'country' => $validated['country'] ?? null,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        foreach (array_values($validated['claim_ids'] ?? []) as $i => $claimId) {
            $module->claims()->attach($claimId, ['sequence_no' => $i + 1]);
        }

        return redirect()->route('admin.content-modules.index')->with('status', 'Content module created.');
    }

    public function edit(ContentModule $contentModule)
    {
        $contentModule->load(['claims', 'rules']);
        $claims = Claim::where('status', 'approved')->orderBy('match_text')->get();
        return view('admin.content-modules.edit', compact('contentModule', 'claims'));
    }

    public function update(Request $request, ContentModule $contentModule)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,approved'],
            'claim_ids' => ['nullable', 'array'],
            'claim_ids.*' => ['exists:claims,id'],
            'rules' => ['nullable', 'string'],
        ]);

        $contentModule->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
        ]);

        $contentModule->claims()->detach();
        foreach (array_values($validated['claim_ids'] ?? []) as $i => $claimId) {
            $contentModule->claims()->attach($claimId, ['sequence_no' => $i + 1]);
        }

        $contentModule->rules()->delete();
        foreach (array_filter(array_map('trim', explode("\n", $validated['rules'] ?? ''))) as $ruleText) {
            $contentModule->rules()->create(['rule_text' => $ruleText]);
        }

        return redirect()->route('admin.content-modules.edit', $contentModule)->with('status', 'Content module updated.');
    }

    public function destroy(ContentModule $contentModule)
    {
        $contentModule->delete();
        return redirect()->route('admin.content-modules.index')->with('status', 'Content module deleted.');
    }
}
