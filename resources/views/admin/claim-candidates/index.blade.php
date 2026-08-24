<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-1">🤖 AI Claim Candidates</h3>
                <p class="text-sm text-gray-500">
                    Statements the AI spotted inside documents that look like reusable, substantiable claims but
                    aren't in the library yet. Nothing here becomes a real claim until you accept it — accepting
                    creates a new claim in <strong>draft</strong> status, still subject to normal claim approval.
                </p>
            </div>

            <div class="flex gap-2 mb-4">
                @foreach (['pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
                    <a href="{{ route('admin.claim-candidates.index', ['status' => $key]) }}"
                       class="text-xs px-3 py-1.5 rounded-full border {{ $status === $key ? 'bg-brand-600 border-brand-600 text-white' : 'border-gray-200 text-gray-600 hover:border-brand-300' }}">
                        {{ $label }}
                        @if ($key !== 'all' && ($counts[$key] ?? 0) > 0)
                            <span class="ml-0.5">({{ $counts[$key] }})</span>
                        @endif
                    </a>
                @endforeach
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg divide-y divide-gray-100">
                @forelse ($candidates as $candidate)
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm text-gray-900">{{ $candidate->suggested_text }}</p>
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                                    @if ($candidate->suggested_category)
                                        <span class="inline-block px-1.5 py-0.5 bg-gray-100 rounded">{{ ucfirst($candidate->suggested_category) }}</span>
                                    @endif
                                    @if ($candidate->confidencePercent() !== null)
                                        <span>Confidence: {{ $candidate->confidencePercent() }}%</span>
                                    @endif
                                    <span>From:
                                        <a href="{{ route('documents.show', $candidate->document) }}" class="text-brand-600 hover:underline">{{ $candidate->document?->title ?? '(deleted document)' }}</a>
                                    </span>
                                    <span>Requested by {{ $candidate->requestedBy?->name }} &middot; {{ $candidate->created_at->diffForHumans() }}</span>
                                </div>
                                @if ($candidate->status !== 'pending')
                                    <p class="mt-1.5 text-xs text-gray-500">
                                        {{ ucfirst($candidate->status) }} by {{ $candidate->reviewedBy?->name }} &middot; {{ $candidate->reviewed_at?->diffForHumans() }}
                                        @if ($candidate->createdClaim)
                                            &middot; <a href="{{ route('admin.claims.edit', $candidate->createdClaim) }}" class="text-brand-600 hover:underline">View created claim &rarr;</a>
                                        @endif
                                    </p>
                                @endif
                            </div>

                            @if ($candidate->status === 'pending')
                                <div class="flex items-center gap-2 shrink-0">
                                    <form method="POST" action="{{ route('admin.claim-candidates.accept', $candidate) }}">
                                        @csrf
                                        <button class="text-xs px-3 py-1.5 bg-brand-600 text-white rounded-md hover:bg-brand-700">Accept</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.claim-candidates.reject', $candidate) }}">
                                        @csrf
                                        <button class="text-xs px-3 py-1.5 border border-gray-200 text-gray-600 rounded-md hover:border-red-300 hover:text-red-600">Reject</button>
                                    </form>
                                </div>
                            @else
                                <span @class([
                                    'text-xs px-2 py-0.5 rounded-full font-medium shrink-0',
                                    'bg-green-100 text-green-700' => $candidate->status === 'accepted',
                                    'bg-gray-100 text-gray-600' => $candidate->status === 'rejected',
                                ])>{{ ucfirst($candidate->status) }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="p-6 text-sm text-gray-500">No {{ $status === 'all' ? '' : $status . ' ' }}claim candidates.</p>
                @endforelse
            </div>

            <div class="mt-4">{{ $candidates->links() }}</div>
        </div>
    </div>
</x-app-layout>
