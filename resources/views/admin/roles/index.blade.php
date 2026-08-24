<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="flex items-center justify-end mb-4">
                <a href="{{ route('admin.roles.create') }}" class="inline-flex items-center px-4 py-2 bg-brand-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-700">
                    + New Role
                </a>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Name</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Description</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Users</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($roles as $role)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    {{ $role->name }}
                                    @if ($role->is_system)
                                        <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500">system</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $role->description ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $role->users_count }}</td>
                                <td class="px-4 py-3 text-right">
                                    @unless ($role->is_system)
                                        <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm('Delete this role?');" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-xs text-red-600 hover:underline">Delete</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
