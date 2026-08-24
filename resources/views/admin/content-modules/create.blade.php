<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">New Content Module</h3>
                <form method="POST" action="{{ route('admin.content-modules.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" required autofocus placeholder="e.g. Natevba Standard Efficacy Block" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" name="description" rows="2" class="mt-1 block w-full border-gray-300 rounded-md">{{ old('description') }}</textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="product" value="Product" />
                            <x-text-input id="product" name="product" class="mt-1 block w-full" :value="old('product')" />
                        </div>
                        <div>
                            <x-input-label for="country" value="Country" />
                            <x-text-input id="country" name="country" class="mt-1 block w-full" :value="old('country')" />
                        </div>
                    </div>

                    <div>
                        <x-input-label value="Claims in this module" />
                        @if ($claims->isEmpty())
                            <p class="text-sm text-gray-500 mt-1">No approved claims yet — <a href="{{ route('admin.claims.create') }}" class="text-brand-600 hover:underline">create one first</a>.</p>
                        @else
                            <div class="mt-2 space-y-1 max-h-64 overflow-y-auto border border-gray-200 rounded-md p-3">
                                @foreach ($claims as $claim)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="claim_ids[]" value="{{ $claim->id }}" class="rounded border-gray-300 text-brand-600" @checked(collect(old('claim_ids'))->contains($claim->id))>
                                        {{ $claim->match_text }}
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Create Module</x-primary-button>
                        <a href="{{ route('admin.content-modules.index') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
