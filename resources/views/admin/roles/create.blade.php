<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">New Role</h3>
                <form method="POST" action="{{ route('admin.roles.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <x-text-input id="description" name="description" class="mt-1 block w-full" :value="old('description')" />
                    </div>

                    <div>
                        <x-input-label value="Permissions" />
                        <div class="mt-2 space-y-3">
                            @foreach ($permissions as $group => $items)
                                <div>
                                    <div class="text-xs font-semibold text-gray-500 uppercase mb-1">{{ $group }}</div>
                                    <div class="grid grid-cols-2 gap-2">
                                        @foreach ($items as $permission)
                                            <label class="flex items-center gap-2 text-sm">
                                                <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" class="rounded border-gray-300 text-brand-600" @checked(collect(old('permissions'))->contains($permission->id))>
                                                {{ $permission->name }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Create Role</x-primary-button>
                        <a href="{{ route('admin.roles.index') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
