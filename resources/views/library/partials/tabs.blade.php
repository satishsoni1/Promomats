{{-- Library sections: every job, or the shared reference files. Expects $active ('jobs' | 'files'). --}}
<nav class="flex gap-1 border-b border-gray-200">
    <a href="{{ route('library.folders.index', ['view' => 'jobs']) }}"
       class="px-3 py-2 text-sm font-medium border-b-2 -mb-px {{ $active === 'jobs' ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">Jobs</a>
    <a href="{{ route('library.folders.index') }}"
       class="px-3 py-2 text-sm font-medium border-b-2 -mb-px {{ $active === 'files' ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">Reference files</a>
</nav>
