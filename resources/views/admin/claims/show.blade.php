<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">{{ $claim->match_text }}</h3>
                    <a href="{{ route('admin.claims.edit', $claim) }}" class="text-sm text-brand-600 hover:underline">Edit</a>
                </div>
                <p class="text-sm text-gray-500 mt-1">{{ $claim->category }} @if($claim->product) &middot; {{ $claim->product }} @endif @if($claim->country) &middot; {{ $claim->country }} @endif</p>
                @if ($claim->body)
                    <p class="mt-3 text-sm text-gray-700">{{ $claim->body }}</p>
                @endif

                @if ($claim->references->isNotEmpty())
                    <div class="mt-4">
                        <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">References</h4>
                        <ul class="text-sm text-gray-700 space-y-1">
                            @foreach ($claim->references as $ref)
                                <li>
                                    {{ $ref->title }}
                                    @if ($ref->citation) — <span class="text-gray-500">{{ $ref->citation }}</span> @endif
                                    @if ($ref->url) (<a href="{{ $ref->url }}" target="_blank" class="text-brand-600 hover:underline">link</a>) @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">Where Used ({{ $claim->documents->count() }})</h3>
                @forelse ($claim->documents as $doc)
                    <a href="{{ route('documents.show', $doc) }}" class="flex items-center justify-between py-2 border-b last:border-b-0 border-gray-100 text-sm hover:bg-gray-50 -mx-2 px-2 rounded">
                        <div>
                            <span class="font-medium text-gray-900">{{ $doc->title }}</span>
                            <span class="text-gray-500 font-mono text-xs ml-1">{{ $doc->reference_no }}</span>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ str($doc->status)->headline() }}</span>
                    </a>
                @empty
                    <p class="text-sm text-gray-500">Not inserted into any document yet.</p>
                @endforelse
            </div>

            @if ($claim->contentModules->isNotEmpty())
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Part of Content Modules</h3>
                    @foreach ($claim->contentModules as $module)
                        <a href="{{ route('admin.content-modules.edit', $module) }}" class="block py-1 text-sm text-brand-600 hover:underline">{{ $module->name }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
