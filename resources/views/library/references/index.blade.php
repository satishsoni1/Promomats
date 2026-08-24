<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">📚 Reference Library</h2>
            <p class="text-sm text-gray-500 mt-0.5">Every reference file uploaded anywhere in VODO, searchable across every team — reuse instead of re-uploading.</p>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($targetDocument)
                <div class="bg-brand-50 border border-brand-100 text-brand-900 text-sm rounded-lg p-4">
                    Browsing to attach a reference to <strong>{{ $targetDocument->title }}</strong>.
                    <a href="{{ route('documents.show', $targetDocument) }}" class="underline">Back to document</a>
                </div>
            @endif

            <form method="GET" action="{{ route('library.references.index') }}" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4 flex flex-wrap items-end gap-3">
                @if ($targetDocument)
                    <input type="hidden" name="document" value="{{ $targetDocument->id }}">
                @endif
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-gray-500 mb-1">Search</label>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Title or filename…" class="w-full text-sm border-gray-300 rounded-md">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Category</label>
                    <select name="category" class="text-sm border-gray-300 rounded-md">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Project</label>
                    <select name="project" class="text-sm border-gray-300 rounded-md">
                        <option value="">All projects</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected((string) request('project') === (string) $project->id)>{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button>Search</x-primary-button>
                @if (request('q') || request('category') || request('project'))
                    <a href="{{ route('library.references.index', $targetDocument ? ['document' => $targetDocument->id] : []) }}" class="text-sm text-gray-500 hover:underline">Clear</a>
                @endif
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg divide-y divide-gray-100">
                @forelse ($references as $reference)
                    <div class="p-5 flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium text-gray-900">{{ $reference->title }}</span>
                                @if ($reference->category)
                                    <span class="text-xs px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded">{{ $reference->category }}</span>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $reference->original_filename }} &middot; {{ $reference->humanFileSize() }} &middot;
                                {{ $reference->attachableLabel() }} &middot;
                                uploaded by {{ $reference->uploader?->name }} &middot; {{ $reference->created_at->diffForHumans() }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <a href="{{ $reference->downloadUrl() }}" class="text-xs text-brand-600 hover:underline">Download</a>

                            @if ($targetDocument)
                                <form method="POST" action="{{ route('library.references.attach', $reference) }}">
                                    @csrf
                                    <input type="hidden" name="document_id" value="{{ $targetDocument->id }}">
                                    <button class="text-xs px-2.5 py-1.5 bg-brand-600 text-white rounded-md hover:bg-brand-700">Attach to {{ \Illuminate\Support\Str::limit($targetDocument->title, 20) }}</button>
                                </form>
                            @elseif ($myDocuments->isNotEmpty())
                                <form method="POST" action="{{ route('library.references.attach', $reference) }}" class="flex items-center gap-1">
                                    @csrf
                                    <select name="document_id" required class="text-xs border-gray-300 rounded-md py-1">
                                        <option value="">Attach to…</option>
                                        @foreach ($myDocuments as $doc)
                                            <option value="{{ $doc->id }}">{{ $doc->title }}</option>
                                        @endforeach
                                    </select>
                                    <button class="text-xs text-brand-600 hover:underline">Go</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="p-6 text-sm text-gray-500">No reference files match your search yet.</p>
                @endforelse
            </div>

            <div>{{ $references->links() }}</div>
        </div>
    </div>
</x-app-layout>
