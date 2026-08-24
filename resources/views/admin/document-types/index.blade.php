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
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Allowed Extensions</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Max Size</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Documents</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($documentTypes as $type)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $type->name }}</td>
                                <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $type->code }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $type->allowed_extensions ? implode(', ', $type->allowed_extensions) : 'Any' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $type->max_file_size_kb ? number_format($type->max_file_size_kb) . ' KB' : 'Unlimited' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $type->documents_count }}</td>
                                <td class="px-4 py-3">
                                    <span @class(['text-xs px-2 py-0.5 rounded-full font-medium', 'bg-green-100 text-green-700' => $type->status === 'active', 'bg-gray-100 text-gray-500' => $type->status !== 'active'])>{{ ucfirst($type->status) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('admin.document-types.toggle-status', $type) }}" class="inline">
                                        @csrf
                                        <button class="text-xs text-brand-600 hover:underline">{{ $type->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">No document types yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Add Document Type</h3>
                <form method="POST" action="{{ route('admin.document-types.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    @csrf
                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" required placeholder="e.g. PDF" />
                    </div>
                    <div>
                        <x-input-label for="code" value="Code (optional)" />
                        <x-text-input id="code" name="code" class="mt-1 block w-full" placeholder="auto-generated" />
                    </div>
                    <div>
                        <x-input-label for="allowed_extensions" value="Allowed Extensions" />
                        <x-text-input id="allowed_extensions" name="allowed_extensions" class="mt-1 block w-full" placeholder="pdf" />
                        <p class="mt-1 text-xs text-gray-500">Comma-separated, no dots.</p>
                    </div>
                    <div>
                        <x-input-label for="max_file_size_kb" value="Max Size (KB)" />
                        <x-text-input type="number" id="max_file_size_kb" name="max_file_size_kb" class="mt-1 block w-full" placeholder="unlimited" />
                    </div>
                    <div class="md:col-span-4">
                        <x-primary-button>Add</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
