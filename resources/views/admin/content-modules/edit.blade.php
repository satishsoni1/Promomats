<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Edit Content Module</h3>
                    <form method="POST" action="{{ route('admin.content-modules.destroy', $contentModule) }}" onsubmit="return confirm('Delete this content module?');">
                        @csrf @method('DELETE')
                        <button class="text-xs text-red-600 hover:underline">Delete</button>
                    </form>
                </div>
                <form method="POST" action="{{ route('admin.content-modules.update', $contentModule) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name', $contentModule->name)" required />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" name="description" rows="2" class="mt-1 block w-full border-gray-300 rounded-md">{{ old('description', $contentModule->description) }}</textarea>
                    </div>

                    <div>
                        <x-input-label for="status" value="Status" />
                        <select id="status" name="status" class="mt-1 block w-full border-gray-300 rounded-md">
                            <option value="draft" @selected($contentModule->status === 'draft')>Draft</option>
                            <option value="approved" @selected($contentModule->status === 'approved')>Approved</option>
                        </select>
                    </div>

                    <div>
                        <x-input-label value="Claims in this module" />
                        <div class="mt-2 space-y-1 max-h-64 overflow-y-auto border border-gray-200 rounded-md p-3">
                            @foreach ($claims as $claim)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="claim_ids[]" value="{{ $claim->id }}" class="rounded border-gray-300 text-brand-600" @checked($contentModule->claims->contains('id', $claim->id))>
                                    {{ $claim->match_text }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <x-input-label for="rules" value="Rules" />
                        <p class="text-xs text-gray-500 mb-1">One rule per line — e.g. "The graph and this text asset must be used together."</p>
                        <textarea id="rules" name="rules" rows="3" class="mt-1 block w-full border-gray-300 rounded-md">{{ old('rules', $contentModule->rules->pluck('rule_text')->implode("\n")) }}</textarea>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Save Changes</x-primary-button>
                        <a href="{{ route('admin.content-modules.index') }}" class="text-sm text-gray-500 hover:underline">Back to list</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
