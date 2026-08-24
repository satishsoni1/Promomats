<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Download Basket</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Document</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Version</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($documents as $document)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('documents.show', $document) }}" class="font-medium text-brand-600 hover:underline">{{ $document->title }}</a>
                                    <div class="text-xs text-gray-500 font-mono">{{ $document->reference_no }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">v{{ $document->currentVersion?->version_no ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('basket.remove', $document) }}">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-500">Your basket is empty.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($documents->isNotEmpty())
                <div class="flex items-center gap-3">
                    <a href="{{ route('basket.download') }}" class="inline-flex items-center px-4 py-2 bg-brand-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-700">
                        Download All (.zip)
                    </a>
                    <form method="POST" action="{{ route('basket.clear') }}" onsubmit="return confirm('Clear the basket?');">
                        @csrf @method('DELETE')
                        <button class="text-sm text-gray-500 hover:underline">Clear basket</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
