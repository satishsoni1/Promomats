@php
    $bucketColors = [
        'draft' => 'bg-gray-300',
        'in_review' => 'bg-sky-500',
        'needs_attention' => 'bg-amber-500',
        'approved' => 'bg-brand-600',
        'expiring' => 'bg-red-500',
        'retired' => 'bg-gray-400',
    ];
    $total = array_sum($breakdown);
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $project->name }}</h2>
                <p class="text-sm text-gray-500 font-mono">{{ $project->code }}</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs px-3 py-1 rounded-full bg-gray-100 text-gray-700 font-medium">{{ ucfirst($project->status) }}</span>
                @if ($canManage)
                    <a href="{{ route('projects.edit', $project) }}" class="text-sm text-brand-600 hover:underline">Edit</a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">

                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Status Overview</h3>
                        @if ($total > 0)
                            <div class="flex h-3 rounded-full overflow-hidden bg-gray-100 mb-3">
                                @foreach ($breakdown as $bucket => $count)
                                    @if ($count > 0)
                                        <div class="{{ $bucketColors[$bucket] }}" style="width: {{ ($count / $total) * 100 }}%" title="{{ \App\Models\Project::BUCKET_LABELS[$bucket] }}: {{ $count }}"></div>
                                    @endif
                                @endforeach
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
                                @foreach ($breakdown as $bucket => $count)
                                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full {{ $bucketColors[$bucket] }}"></span>{{ $count }} {{ \App\Models\Project::BUCKET_LABELS[$bucket] }}</span>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-gray-500">No documents assigned to this project yet.</p>
                        @endif
                    </div>

                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Documents ({{ $documents->total() }})</h3>
                        <table class="min-w-full text-sm divide-y divide-gray-100">
                            <thead>
                                <tr class="text-left text-gray-500">
                                    <th class="py-2">Title</th>
                                    <th class="py-2">Cycle</th>
                                    <th class="py-2">Status</th>
                                    <th class="py-2">Owner</th>
                                    <th class="py-2">Updated</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($documents as $document)
                                    <tr>
                                        <td class="py-2">
                                            <a href="{{ route('documents.show', $document) }}" class="text-brand-600 hover:underline">{{ $document->title }}</a>
                                            <div class="text-xs text-gray-400 font-mono">{{ $document->reference_no }}</div>
                                        </td>
                                        <td class="py-2 text-gray-600">
                                            @if ($document->cycle)
                                                <a href="{{ route('projects.cycles.show', [$project, $document->cycle]) }}" class="text-xs px-2 py-0.5 rounded-full bg-brand-50 text-brand-700 hover:bg-brand-100">{{ $document->cycle->name }}</a>
                                            @else
                                                <span class="text-xs text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="py-2"><span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ $document->statusLabel() }}</span></td>
                                        <td class="py-2 text-gray-600">{{ $document->owner?->name }}</td>
                                        <td class="py-2 text-gray-500">{{ $document->updated_at->diffForHumans() }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="py-4 text-gray-500">No documents yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div class="mt-4">{{ $documents->links() }}</div>
                    </div>

                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-lg font-semibold text-gray-900">Cycles ({{ $project->cycles->count() }})</h3>
                            <button type="button" onclick="document.getElementById('new-cycle-form').classList.toggle('hidden')" class="text-sm text-brand-600 hover:underline">+ New Cycle</button>
                        </div>
                        <p class="text-xs text-gray-500 mb-3">Rounds/iterations within this project — e.g. "Q1 2026 Launch Wave" — each with its own document set and status breakdown.</p>

                        <form id="new-cycle-form" method="POST" action="{{ route('projects.cycles.store', $project) }}" class="hidden mb-4 p-4 bg-gray-50 rounded-md space-y-3">
                            @csrf
                            <input type="text" name="name" required placeholder="Cycle name (e.g. Q1 2026 Launch Wave)" class="block w-full text-sm border-gray-300 rounded-md">
                            <div class="grid grid-cols-2 gap-3">
                                <input type="date" name="start_date" class="text-sm border-gray-300 rounded-md">
                                <input type="date" name="end_date" class="text-sm border-gray-300 rounded-md">
                            </div>
                            <x-primary-button>Create Cycle</x-primary-button>
                        </form>

                        @forelse ($project->cycles as $cycle)
                            @php $cycleTotal = array_sum($cycle->breakdown); @endphp
                            <a href="{{ route('projects.cycles.show', [$project, $cycle]) }}" class="block py-3 border-t border-gray-100 first:border-t-0 hover:bg-gray-50 -mx-2 px-2 rounded">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-medium text-gray-900">{{ $cycle->name }}</span>
                                    <span @class([
                                        'text-xs px-2 py-0.5 rounded-full font-medium',
                                        'bg-green-100 text-green-700' => $cycle->status === 'active',
                                        'bg-gray-100 text-gray-600' => $cycle->status === 'completed',
                                        'bg-gray-50 text-gray-400' => $cycle->status === 'archived',
                                    ])>{{ ucfirst($cycle->status) }}</span>
                                </div>
                                <p class="text-xs text-gray-500 mb-1.5">{{ $cycleTotal }} document(s) &middot; created by {{ $cycle->creator?->name }}</p>
                                @if ($cycleTotal > 0)
                                    <div class="flex h-1.5 rounded-full overflow-hidden bg-gray-100">
                                        @foreach ($cycle->breakdown as $bucket => $count)
                                            @if ($count > 0)
                                                <div class="{{ $bucketColors[$bucket] }}" style="width: {{ ($count / $cycleTotal) * 100 }}%"></div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </a>
                        @empty
                            <p class="text-sm text-gray-500">No cycles yet — create the first one.</p>
                        @endforelse
                    </div>

                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-lg font-semibold text-gray-900">Project Library ({{ $project->referenceAttachments->count() }})</h3>
                            <div class="flex items-center gap-3">
                                <a href="{{ route('library.references.index', ['project' => $project->id]) }}" class="text-sm text-brand-600 hover:underline">View all project files &rarr;</a>
                                <button type="button" onclick="document.getElementById('upload-project-reference-form').classList.toggle('hidden')" class="text-sm text-brand-600 hover:underline">+ Add file</button>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mb-3">Shared files for this project — reference material, templates, source studies — reusable across every team member's documents (also searchable in the <a href="{{ route('library.references.index') }}" class="text-brand-600 hover:underline">Reference Library</a>), not tied to any one document.</p>

                        <form id="upload-project-reference-form" method="POST" action="{{ route('projects.references.store', $project) }}" enctype="multipart/form-data" class="hidden mb-4 p-4 bg-gray-50 rounded-md space-y-3">
                            @csrf
                            <input type="text" name="title" required placeholder="File title" class="block w-full text-sm border-gray-300 rounded-md">
                            <input type="text" name="category" placeholder="Category (optional)" class="block w-full text-sm border-gray-300 rounded-md">
                            <input type="file" name="file" required class="block w-full text-sm">
                            <x-primary-button>Add to Project Library</x-primary-button>
                        </form>

                        @forelse ($project->referenceAttachments as $reference)
                            <div class="flex items-center justify-between py-2 border-t border-gray-100 first:border-t-0 text-sm">
                                <div>
                                    <span class="text-gray-900">{{ $reference->title }}</span>
                                    @if ($reference->category)
                                        <span class="text-xs px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded ml-1">{{ $reference->category }}</span>
                                    @endif
                                    <span class="text-xs text-gray-500 ml-1">{{ $reference->original_filename }} &middot; {{ $reference->humanFileSize() }} &middot; {{ $reference->uploader?->name }}</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <a href="{{ $reference->downloadUrl() }}" class="text-brand-600 hover:underline text-xs">Download</a>
                                    <form method="POST" action="{{ route('projects.references.destroy', [$project, $reference]) }}" onsubmit="return confirm('Remove this file?');">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-gray-400 hover:text-red-600">&times;</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">No shared files yet.</p>
                        @endforelse
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Details</h3>
                        <dl class="text-sm space-y-3">
                            <div>
                                <dt class="text-gray-500">Lead</dt>
                                <dd class="text-gray-900">{{ $project->lead?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Created by</dt>
                                <dd class="text-gray-900">{{ $project->creator?->name }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Target Audience</dt>
                                <dd class="text-gray-900">{{ $project->targetAudienceLabel() ?? '—' }}</dd>
                            </div>
                            @if ($project->description)
                                <div>
                                    <dt class="text-gray-500">Description</dt>
                                    <dd class="text-gray-900 whitespace-pre-line">{{ $project->description }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
