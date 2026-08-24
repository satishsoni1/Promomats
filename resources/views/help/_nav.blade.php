<div class="flex gap-4 border-b border-gray-200 mb-6 text-sm">
    <a href="{{ route('help.index') }}" class="pb-2 px-1 {{ request()->routeIs('help.index') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">User Guide</a>
    <a href="{{ route('help.demo-script') }}" class="pb-2 px-1 {{ request()->routeIs('help.demo-script') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Client Demo Script</a>
</div>
