<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="flex items-center justify-between mb-4">
                <form method="GET" class="flex gap-2">
                    <x-text-input name="search" value="{{ request('search') }}" placeholder="Search name or email" />
                    <x-primary-button type="submit">Search</x-primary-button>
                </form>
                <a href="{{ route('admin.users.create') }}" class="inline-flex items-center px-4 py-2 bg-brand-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-700">
                    + New User
                </a>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Name</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Email</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Roles</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($users as $user)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $user->name }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $user->email }}</td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('admin.users.roles.update', $user) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 max-w-md">
                                        @csrf
                                        @foreach ($roles as $role)
                                            <label class="flex items-center gap-1 text-xs whitespace-nowrap">
                                                <input type="checkbox" name="roles[]" value="{{ $role->id }}" class="rounded border-gray-300 text-brand-600" @checked($user->roles->contains('id', $role->id))>
                                                {{ $role->name }}
                                            </label>
                                        @endforeach
                                        <button class="text-xs text-brand-600 hover:underline font-medium">Save</button>
                                    </form>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs px-2 py-0.5 rounded-full {{ $user->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $user->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}">
                                        @csrf
                                        <button class="text-xs text-gray-600 hover:underline">{{ $user->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $users->withQueryString()->links() }}
        </div>
    </div>
</x-app-layout>
