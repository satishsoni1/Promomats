<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Project</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <form method="POST" action="{{ route('projects.update', $project) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="name" value="Project Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name', $project->name)" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="code" value="Short Code (optional)" />
                        <x-text-input id="code" name="code" class="mt-1 block w-full" :value="old('code', $project->code)" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md">{{ old('description', $project->description) }}</textarea>
                    </div>

                    <div>
                        <x-input-label for="target_audience" value="Target Audience" />
                        <select id="target_audience" name="target_audience" class="mt-1 block w-full border-gray-300 rounded-md">
                            <option value="">— Not specified —</option>
                            @foreach (\App\Support\TargetAudience::LABELS as $key => $label)
                                <option value="{{ $key }}" @selected(old('target_audience', $project->target_audience) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Default audience for documents created under this project — each document can still override it.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="lead_id" value="Project Lead" />
                            <select id="lead_id" name="lead_id" class="mt-1 block w-full border-gray-300 rounded-md">
                                <option value="">— None —</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}" @selected(old('lead_id', $project->lead_id) == $user->id)>{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="status" value="Status" />
                            <select id="status" name="status" class="mt-1 block w-full border-gray-300 rounded-md">
                                <option value="active" @selected(old('status', $project->status) === 'active')>Active</option>
                                <option value="archived" @selected(old('status', $project->status) === 'archived')>Archived</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Save Changes</x-primary-button>
                        <a href="{{ route('projects.show', $project) }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
