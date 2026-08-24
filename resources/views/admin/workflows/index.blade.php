<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="flex items-center justify-end mb-4">
                <a href="{{ route('admin.workflows.create') }}" class="inline-flex items-center px-4 py-2 bg-brand-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-700">
                    + New Workflow
                </a>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Name</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Code</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Applies To</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Stages</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($templates as $template)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $template->name }}</td>
                                <td class="px-4 py-3 text-gray-600 font-mono text-xs">{{ $template->code }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $template->applies_to_category ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $template->stages_count }}</td>
                                <td class="px-4 py-3">
                                    <span class="text-xs px-2 py-0.5 rounded-full {{ $template->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $template->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('admin.workflows.edit', $template) }}" class="text-xs text-brand-600 hover:underline">Configure</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
