@php
    // Fixed, reserved status colors (not a generic categorical palette) - reused
    // identically here, on projects/show.blade.php, and anywhere else a status
    // breakdown is rendered, so the same color always means the same thing.
    $bucketColors = [
        'draft' => 'bg-gray-300',
        'in_review' => 'bg-sky-500',
        'needs_attention' => 'bg-amber-500',
        'approved' => 'bg-brand-600',
        'expiring' => 'bg-red-500',
        'retired' => 'bg-gray-400',
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">📊 Projects</h2>
                <p class="text-sm text-gray-500 mt-0.5">Every team's initiatives, with a live status breakdown of their documents.</p>
            </div>
            <a href="{{ route('projects.create') }}">
                <x-primary-button>+ New Project</x-primary-button>
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <form method="GET" action="{{ route('projects.index') }}" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-gray-500 mb-1">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Project name…" class="w-full text-sm border-gray-300 rounded-md">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Status</label>
                    <select name="status" class="text-sm border-gray-300 rounded-md">
                        <option value="active" @selected(request('status', 'active') === 'active')>Active</option>
                        <option value="archived" @selected(request('status') === 'archived')>Archived</option>
                    </select>
                </div>
                <x-primary-button>Filter</x-primary-button>
            </form>

            @if ($unassignedCount > 0)
                <div class="bg-gray-50 border border-gray-200 text-gray-600 text-sm rounded-lg p-4 flex items-center justify-between">
                    <span>{{ $unassignedCount }} document(s) aren't assigned to any project yet.</span>
                    <a href="{{ route('documents.index') }}" class="text-brand-600 hover:underline">View all documents &rarr;</a>
                </div>
            @endif

            <!-- Legend -->
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                @foreach (\App\Models\Project::BUCKET_LABELS as $bucket => $label)
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full {{ $bucketColors[$bucket] }}"></span>{{ $label }}</span>
                @endforeach
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                @forelse ($projects as $project)
                    @php $total = array_sum($project->breakdown); @endphp
                    <a href="{{ route('projects.show', $project) }}" class="block bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-5 hover:ring-brand-300 transition">
                        <div class="flex items-start justify-between gap-2 mb-1">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ $project->name }}</h3>
                                @if ($project->code)
                                    <span class="text-xs font-mono text-gray-400">{{ $project->code }}</span>
                                @endif
                            </div>
                            @if ($project->overdueCount > 0)
                                <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-medium whitespace-nowrap">🚩 {{ $project->overdueCount }} overdue</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 mb-3">{{ $project->documents_count }} document(s) &middot; Lead: {{ $project->lead?->name ?? '—' }}</p>

                        @if ($total > 0)
                            <div class="flex h-2.5 rounded-full overflow-hidden bg-gray-100 mb-2">
                                @foreach ($project->breakdown as $bucket => $count)
                                    @if ($count > 0)
                                        <div class="{{ $bucketColors[$bucket] }}" style="width: {{ ($count / $total) * 100 }}%" title="{{ \App\Models\Project::BUCKET_LABELS[$bucket] }}: {{ $count }}"></div>
                                    @endif
                                @endforeach
                            </div>
                            <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-600">
                                @foreach ($project->breakdown as $bucket => $count)
                                    @if ($count > 0)
                                        <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full {{ $bucketColors[$bucket] }}"></span>{{ $count }} {{ \App\Models\Project::BUCKET_LABELS[$bucket] }}</span>
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-gray-400">No documents assigned yet.</p>
                        @endif
                    </a>
                @empty
                    <p class="text-sm text-gray-500 col-span-2">No projects yet — create the first one.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
