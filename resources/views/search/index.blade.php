<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Search results for "{{ $q }}"</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($aiAvailable && $q !== '')
                <div class="bg-brand-50 border border-brand-100 rounded-lg p-4 text-sm text-brand-800 flex items-center justify-between">
                    <span>Looking for something these exact-text results miss? Try describing it in plain English.</span>
                    <a href="{{ route('search.ai', ['q' => $q]) }}" class="font-semibold underline whitespace-nowrap ml-3">🤖 Ask AI Search &rarr;</a>
                </div>
            @endif

            @if ($q === '')
                <p class="text-sm text-gray-500">Type something in the search box above.</p>

                @if ($aiAvailable)
                    <form method="GET" action="{{ route('search.ai') }}" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-2">🤖 Or ask AI Search a plain-English question</h3>
                        <div class="flex gap-2">
                            <x-text-input name="q" class="flex-1" placeholder="e.g. documents mentioning cardiovascular risk expiring this quarter" />
                            <x-primary-button>Ask</x-primary-button>
                        </div>
                    </form>
                @endif
            @else
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Documents ({{ $documents->count() }})</h3>
                    @forelse ($documents as $document)
                        <a href="{{ route('documents.show', $document) }}" class="flex items-center justify-between py-2.5 border-b last:border-b-0 border-gray-100 text-sm hover:bg-gray-50 -mx-2 px-2 rounded">
                            <div>
                                <span class="font-medium text-gray-900">{{ $document->title }}</span>
                                <span class="text-gray-500 font-mono text-xs ml-1">{{ $document->reference_no }}</span>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ $document->statusLabel() }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No matching documents.</p>
                    @endforelse
                </div>

                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Claims ({{ $claims->count() }})</h3>
                    @forelse ($claims as $claim)
                        <a href="{{ route('admin.claims.show', $claim) }}" class="flex items-center justify-between py-2.5 border-b last:border-b-0 border-gray-100 text-sm hover:bg-gray-50 -mx-2 px-2 rounded">
                            <span class="text-gray-900">{{ $claim->match_text }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ ucfirst($claim->status) }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No matching claims.</p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
