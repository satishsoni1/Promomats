<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">New Workflow</h3>
                <form method="POST" action="{{ route('admin.workflows.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" required autofocus placeholder="e.g. Pharma Workflow 2" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="code" value="Code" />
                        <x-text-input id="code" name="code" class="mt-1 block w-full" :value="old('code')" required placeholder="e.g. WF2" />
                        <x-input-error :messages="$errors->get('code')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="applies_to_category" value="Applies to Category" />
                        <x-text-input id="applies_to_category" name="applies_to_category" class="mt-1 block w-full" :value="old('applies_to_category')" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" name="description" rows="3" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">{{ old('description') }}</textarea>
                    </div>

                    <div>
                        <x-input-label value="Recommended For Target Audiences" />
                        <p class="text-xs text-gray-500 mt-0.5 mb-2">When a user picks one of these audiences on a document, this workflow gets auto-suggested (they can still change it).</p>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (\App\Support\TargetAudience::LABELS as $key => $label)
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="target_audiences[]" value="{{ $key }}" @checked(in_array($key, old('target_audiences', []))) class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Create &amp; Add Stages</x-primary-button>
                        <a href="{{ route('admin.workflows.index') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
