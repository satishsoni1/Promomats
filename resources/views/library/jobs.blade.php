<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">📚 Library</h2>
            <p class="text-sm text-gray-500 mt-0.5">Every job you can see — in progress (as a stakeholder) and approved material.</p>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @include('library.partials.tabs', ['active' => 'jobs'])

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="view" value="jobs">
                <input type="hidden" name="stage" value="{{ $stage }}">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Search title or reference no…" class="w-full sm:w-72 text-sm border-gray-300 rounded-lg">
                <button class="px-3 py-2 text-sm bg-white border border-gray-200 rounded-lg hover:bg-gray-50">Search</button>
                <div class="flex gap-1 sm:ml-auto text-xs">
                    @foreach (['all' => 'All', 'progress' => 'In progress', 'approved' => 'Approved'] as $key => $label)
                        <a href="{{ route('library.folders.index', array_filter(['view' => 'jobs', 'stage' => $key, 'q' => request('q')])) }}"
                           class="px-2.5 py-1.5 rounded-full border {{ $stage === $key ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-600 border-gray-200 hover:border-brand-300' }}">{{ $label }} ({{ $counts[$key] }})</a>
                    @endforeach
                </div>
            </form>

            <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-100">
                    <thead>
                        <tr class="text-left text-gray-400 text-xs uppercase tracking-wide">
                            <th class="px-4 py-2.5 font-medium">Job</th>
                            <th class="px-4 py-2.5 font-medium">Type</th>
                            <th class="px-4 py-2.5 font-medium">Brand Manager</th>
                            <th class="px-4 py-2.5 font-medium">Status</th>
                            <th class="px-4 py-2.5 font-medium">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($jobs as $job)
                            <tr class="hover:bg-gray-50/70">
                                <td class="px-4 py-3">
                                    <a href="{{ route('documents.show', $job) }}" class="font-medium text-gray-900 hover:text-brand-700">{{ $job->title }}</a>
                                    <div class="text-xs text-gray-400 font-mono">{{ $job->reference_no }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $job->documentType?->name ?? '—' }} @if ($job->brand)<span class="text-gray-400"> · {{ $job->brand->name }}</span>@endif</td>
                                <td class="px-4 py-3 text-gray-600">{{ $job->owner?->name }}</td>
                                <td class="px-4 py-3"><x-status-badge :status="$job->status">{{ $job->statusLabel() }}</x-status-badge></td>
                                <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $job->updated_at?->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">No jobs found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $jobs->links() }}
        </div>
    </div>
</x-app-layout>
