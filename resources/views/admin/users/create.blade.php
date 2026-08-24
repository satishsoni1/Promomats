<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">New User</h3>
                <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-5">
                    @csrf

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name')" required autofocus />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="employee_code" value="Employee Code" />
                            <x-text-input id="employee_code" name="employee_code" class="mt-1 block w-full" :value="old('employee_code')" />
                            <x-input-error :messages="$errors->get('employee_code')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="email" value="Email" />
                        <x-text-input type="email" id="email" name="email" class="mt-1 block w-full" :value="old('email')" required />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="department" value="Department" />
                            <x-text-input id="department" name="department" class="mt-1 block w-full" :value="old('department')" />
                        </div>
                        <div>
                            <x-input-label for="designation" value="Designation" />
                            <x-text-input id="designation" name="designation" class="mt-1 block w-full" :value="old('designation')" />
                        </div>
                    </div>

                    <div>
                        <x-input-label value="Roles" />
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            @foreach ($roles as $role)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="roles[]" value="{{ $role->id }}" class="rounded border-gray-300 text-brand-600" @checked(collect(old('roles'))->contains($role->id))>
                                    {{ $role->name }}
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('roles')" class="mt-2" />
                    </div>

                    <p class="text-xs text-gray-500">A random temporary password will be generated and shown after creation. The user should change it on first login.</p>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Create User</x-primary-button>
                        <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
