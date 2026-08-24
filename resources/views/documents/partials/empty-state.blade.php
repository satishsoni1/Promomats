<div class="flex flex-col items-center justify-center text-center py-16 px-4">
    <div class="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center text-2xl mb-3">🗂️</div>
    <p class="text-sm font-medium text-gray-700">No documents found</p>
    <p class="text-xs text-gray-400 mt-1 max-w-xs">Try adjusting your search or filters, or upload a new document to get started.</p>
    @if (request()->anyFilled(['status', 'category', 'search', 'mine']))
        <a href="{{ route('documents.index') }}" class="mt-4 text-xs text-brand-600 hover:underline font-medium">Clear all filters</a>
    @else
        <a href="{{ route('documents.create') }}" class="mt-4 inline-flex items-center px-3.5 py-1.5 bg-brand-600 rounded-lg text-xs font-semibold text-white hover:bg-brand-700 transition">Upload Document</a>
    @endif
</div>
