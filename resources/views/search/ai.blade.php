<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">🤖 AI Search</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-lg p-4">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="GET" action="{{ route('search.ai') }}" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <x-input-label for="q" value="Ask a question about the document library" />
                <div class="flex gap-2 mt-1">
                    <x-text-input id="q" name="q" class="flex-1" value="{{ $q }}" placeholder="e.g. documents mentioning cardiovascular risk expiring this quarter" autofocus />
                    <x-primary-button>Ask</x-primary-button>
                </div>
                <a href="{{ route('search', ['q' => $q]) }}" class="text-xs text-gray-500 hover:underline mt-2 inline-block">&larr; Back to keyword search</a>
            </form>

            @if ($q !== '')
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Results ({{ $results->count() }})</h3>
                    @forelse ($results as $row)
                        <a href="{{ route('documents.show', $row['document']) }}" class="block py-3 border-b last:border-b-0 border-gray-100 hover:bg-gray-50 -mx-2 px-2 rounded">
                            <div class="flex items-center justify-between">
                                <span class="font-medium text-gray-900">{{ $row['document']->title }}</span>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ $row['document']->statusLabel() }}</span>
                            </div>
                            <div class="text-xs text-gray-500 font-mono">{{ $row['document']->reference_no }}</div>
                            <p class="text-sm text-brand-700 mt-1">🤖 {{ $row['reason'] }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">The AI didn't find a match for that question.</p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
