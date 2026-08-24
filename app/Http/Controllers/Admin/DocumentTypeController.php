<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentTypeController extends Controller
{
    public function index()
    {
        $documentTypes = DocumentType::withCount('documents')->orderBy('name')->get();
        return view('admin.document-types.index', compact('documentTypes'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:document_types,code'],
            'allowed_extensions' => ['nullable', 'string', 'max:255'],
            'max_file_size_kb' => ['nullable', 'integer', 'min:1'],
        ]);

        $extensions = collect(explode(',', (string) $validated['allowed_extensions']))
            ->map(fn ($ext) => strtolower(trim($ext, " \t\n\r\0\x0B.")))
            ->filter()
            ->values()
            ->all();

        DocumentType::create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?: Str::upper(Str::slug($validated['name'], '_')),
            'allowed_extensions' => $extensions ?: null,
            'max_file_size_kb' => $validated['max_file_size_kb'] ?? null,
            'status' => 'active',
        ]);

        return back()->with('status', 'Document type added.');
    }

    public function toggleStatus(DocumentType $documentType)
    {
        $documentType->update(['status' => $documentType->status === 'active' ? 'inactive' : 'active']);

        return back()->with('status', "Document type \"{$documentType->name}\" is now {$documentType->status}.");
    }
}
