<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden mb-6">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Name</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Code</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Documents</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($brands as $brand)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $brand->name }}</td>
                                <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $brand->code }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $brand->documents_count }}</td>
                                <td class="px-4 py-3">
                                    <span @class(['text-xs px-2 py-0.5 rounded-full font-medium', 'bg-green-100 text-green-700' => $brand->status === 'active', 'bg-gray-100 text-gray-500' => $brand->status !== 'active'])>{{ ucfirst($brand->status) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('admin.brands.toggle-status', $brand) }}" class="inline">
                                        @csrf
                                        <button class="text-xs text-brand-600 hover:underline">{{ $brand->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No brands yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Add Brand</h3>
                <form method="POST" action="{{ route('admin.brands.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    @csrf
                    <div class="md:col-span-2">
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" required placeholder="e.g. Liv.52" />
                    </div>
                    <div>
                        <x-input-label for="code" value="Code (optional)" />
                        <x-text-input id="code" name="code" class="mt-1 block w-full" placeholder="auto-generated" />
                    </div>
                    <x-primary-button>Add</x-primary-button>
                    <div class="md:col-span-4">
                        <x-input-label for="description" value="Description (optional)" />
                        <x-text-input id="description" name="description" class="mt-1 block w-full" />
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
