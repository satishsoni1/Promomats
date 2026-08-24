<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">📚 Library</h2>
            <p class="text-sm text-gray-500 mt-0.5">Full-text search across every team's files, organized in folders — reuse instead of re-uploading.</p>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($targetDocument)
                <div class="bg-brand-50 border border-brand-100 text-brand-900 text-sm rounded-lg p-4">
                    Browsing to attach a file to <strong>{{ $targetDocument->title }}</strong>.
                    <a href="{{ route('documents.show', $targetDocument) }}" class="underline">Back to document</a>
                </div>
            @endif

            <!-- Breadcrumbs -->
            <nav class="flex items-center gap-1.5 text-sm text-gray-500 flex-wrap">
                <a href="{{ route('library.folders.index', $targetDocument ? ['document' => $targetDocument->id] : []) }}" class="hover:text-brand-600 font-medium {{ ! $folder ? 'text-gray-900' : '' }}">Library</a>
                @foreach ($breadcrumbs as $crumb)
                    <span class="text-gray-300">/</span>
                    <a href="{{ route('library.folders.show', array_filter([$crumb->id, 'document' => $targetDocument?->id])) }}"
                       class="hover:text-brand-600 {{ $crumb->id === $folder?->id ? 'text-gray-900 font-medium' : '' }}">{{ $crumb->name }}</a>
                @endforeach
                <a href="{{ route('library.references.index') }}" class="ml-auto text-xs text-brand-600 hover:underline">🔍 Search all files across every folder &rarr;</a>
            </nav>

            <!-- Search / filter -->
            <form method="GET" action="{{ $folder ? route('library.folders.show', $folder) : route('library.folders.index') }}" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4 flex flex-wrap items-end gap-3">
                @if ($targetDocument)
                    <input type="hidden" name="document" value="{{ $targetDocument->id }}">
                @endif
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs text-gray-500 mb-1">Name</label>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="File title or filename…" class="w-full text-sm border-gray-300 rounded-md">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Owner</label>
                    <select name="owner_id" class="text-sm border-gray-300 rounded-md">
                        <option value="">Anyone</option>
                        @foreach ($owners as $owner)
                            <option value="{{ $owner->id }}" @selected((string) request('owner_id') === (string) $owner->id)>{{ $owner->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">From</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="text-sm border-gray-300 rounded-md">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">To</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="text-sm border-gray-300 rounded-md">
                </div>
                <x-primary-button>Filter</x-primary-button>
                @if (request('q') || request('owner_id') || request('date_from') || request('date_to'))
                    <a href="{{ $folder ? route('library.folders.show', $folder) : route('library.folders.index') }}" class="text-sm text-gray-500 hover:underline">Clear</a>
                @endif
            </form>

            <div class="flex items-center justify-end gap-3">
                <button type="button" onclick="document.getElementById('new-folder-form').classList.toggle('hidden')" class="text-sm text-brand-600 hover:underline">+ New Folder</button>
                <button type="button" onclick="document.getElementById('upload-file-form').classList.toggle('hidden')" class="text-sm text-brand-600 hover:underline">+ Upload File</button>
            </div>

            <form id="new-folder-form" method="POST" action="{{ route('library.folders.store') }}" class="hidden bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4 flex items-end gap-3">
                @csrf
                <input type="hidden" name="parent_id" value="{{ $folder?->id }}">
                <div class="flex-1">
                    <label class="block text-xs text-gray-500 mb-1">Folder name</label>
                    <input type="text" name="name" required placeholder="e.g. Clinical Studies" class="w-full text-sm border-gray-300 rounded-md">
                </div>
                <x-primary-button>Create Folder</x-primary-button>
            </form>

            <form id="upload-file-form" method="POST" action="{{ route('library.files.store') }}" enctype="multipart/form-data" class="hidden bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4 space-y-3">
                @csrf
                <input type="hidden" name="folder_id" value="{{ $folder?->id }}">
                <p class="text-xs text-gray-500">Uploading into: <strong>{{ $folder?->name ?? 'Library (root)' }}</strong></p>
                <div class="grid grid-cols-2 gap-3">
                    <input type="text" name="title" required placeholder="File title" class="text-sm border-gray-300 rounded-md">
                    <input type="text" name="category" placeholder="Category (optional)" class="text-sm border-gray-300 rounded-md">
                </div>
                <input type="file" name="file" required class="block w-full text-sm">
                <x-primary-button>Upload</x-primary-button>
            </form>

            <!-- Subfolders -->
            @if ($subfolders->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($subfolders as $sub)
                        <a href="{{ route('library.folders.show', array_filter([$sub->id, 'document' => $targetDocument?->id])) }}"
                           class="flex items-start gap-3 bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4 hover:ring-brand-300 transition">
                            <svg class="w-8 h-8 text-brand-500 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6.75A1.25 1.25 0 0 1 5.25 5.5h4.19c.3 0 .59.12.8.33l1.51 1.42h7a1.25 1.25 0 0 1 1.25 1.25v9.25a1.25 1.25 0 0 1-1.25 1.25H5.25A1.25 1.25 0 0 1 4 17.25V6.75Z" /></svg>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $sub->name }}</p>
                                <p class="text-xs text-gray-500">{{ $sub->children_count }} folder(s), {{ $sub->files_count }} file(s)</p>
                                <p class="text-xs text-gray-400">by {{ $sub->creator?->name }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif

            <!-- Files -->
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg divide-y divide-gray-100">
                @forelse ($files as $file)
                    <div class="p-4 flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium text-gray-900">{{ $file->title }}</span>
                                @if ($file->category)
                                    <span class="text-xs px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded">{{ $file->category }}</span>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $file->original_filename }} &middot; {{ $file->humanFileSize() }} &middot;
                                uploaded by {{ $file->uploader?->name }} &middot; {{ $file->created_at->diffForHumans() }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <a href="{{ $file->downloadUrl() }}" class="text-xs text-brand-600 hover:underline">Download</a>
                            @if ($targetDocument)
                                <form method="POST" action="{{ route('library.references.attach', $file) }}">
                                    @csrf
                                    <input type="hidden" name="document_id" value="{{ $targetDocument->id }}">
                                    <button class="text-xs px-2.5 py-1.5 bg-brand-600 text-white rounded-md hover:bg-brand-700">Attach</button>
                                </form>
                            @elseif ($myDocuments->isNotEmpty())
                                <form method="POST" action="{{ route('library.references.attach', $file) }}" class="flex items-center gap-1">
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
                            @if (auth()->id() === $file->uploaded_by || auth()->user()->can('access-admin'))
                                <form method="POST" action="{{ route('library.files.destroy', $file) }}" onsubmit="return confirm('Remove this file?');">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-gray-400 hover:text-red-600">&times;</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="p-6 text-sm text-gray-500">No files here yet.</p>
                @endforelse
            </div>
            <div>{{ $files->links() }}</div>

            @if ($folder)
                <form method="POST" action="{{ route('library.folders.destroy', $folder) }}" onsubmit="return confirm('Remove this empty folder?');">
                    @csrf @method('DELETE')
                    <button class="text-xs text-gray-400 hover:text-red-600">Delete this folder (must be empty)</button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
