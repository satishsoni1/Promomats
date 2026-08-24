<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">New Project</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <form method="POST" action="{{ route('projects.store') }}" class="space-y-5"
                      x-data="{ audience: @js(old('target_audience', '')), guidanceMap: @js(\App\Support\TargetAudience::GUIDANCE) }">
                    @csrf

                    <div>
                        <x-input-label for="name" value="Project Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" required autofocus placeholder="e.g. Immunobooster Q3 Launch" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="code" value="Short Code (optional)" />
                        <x-text-input id="code" name="code" class="mt-1 block w-full" :value="old('code')" placeholder="e.g. IMB-Q3" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md">{{ old('description') }}</textarea>
                    </div>

                    <div>
                        <x-input-label for="target_audience" value="Target Audience" />
                        <select id="target_audience" name="target_audience" x-model="audience" class="mt-1 block w-full border-gray-300 rounded-md">
                            <option value="">— Not specified —</option>
                            @foreach (\App\Support\TargetAudience::LABELS as $key => $label)
                                <option value="{{ $key }}" @selected(old('target_audience') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Default audience for documents created under this project — each document can still override it.</p>
                    </div>

                    <template x-if="audience && guidanceMap[audience]">
                        <div class="rounded-md p-3 text-xs border flex items-start gap-2"
                             :class="{
                                 'bg-red-50 border-red-200 text-red-800': guidanceMap[audience].depth === 'high',
                                 'bg-amber-50 border-amber-200 text-amber-800': guidanceMap[audience].depth === 'medium',
                                 'bg-gray-50 border-gray-200 text-gray-600': guidanceMap[audience].depth === 'low',
                             }">
                            <span class="text-sm leading-none" x-text="guidanceMap[audience].depth === 'high' ? '⚠️' : (guidanceMap[audience].depth === 'medium' ? '◐' : 'ℹ️')"></span>
                            <div>
                                <p class="font-semibold" x-text="guidanceMap[audience].depth === 'high' ? 'Deep review required' : (guidanceMap[audience].depth === 'medium' ? 'Standard review' : 'Lightweight review')"></p>
                                <p class="mt-0.5" x-text="guidanceMap[audience].message"></p>
                            </div>
                        </div>
                    </template>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Create Project</x-primary-button>
                        <a href="{{ route('projects.index') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
