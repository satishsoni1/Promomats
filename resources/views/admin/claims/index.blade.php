<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="flex items-center justify-between mb-4">
                <form method="GET" class="flex gap-2">
                    <x-text-input name="search" value="{{ request('search') }}" placeholder="Search claim text" />
                    <select name="status" class="text-sm border-gray-300 rounded-md">
                        <option value="">Any status</option>
                        @foreach (['draft','approved','expired'] as $s)
                            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                    <x-primary-button type="submit">Filter</x-primary-button>
                </form>
                <a href="{{ route('admin.claims.create') }}" class="inline-flex items-center px-4 py-2 bg-brand-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-700">
                    + New Claim
                </a>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Claim</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Category</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Product</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Where Used</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($claims as $claim)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900 max-w-sm truncate">{{ $claim->match_text }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $claim->category ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $claim->product ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'text-xs px-2 py-0.5 rounded-full',
                                        'bg-green-100 text-green-700' => $claim->status === 'approved',
                                        'bg-gray-100 text-gray-600' => $claim->status === 'draft',
                                        'bg-red-100 text-red-700' => $claim->status === 'expired',
                                    ])>{{ ucfirst($claim->status) }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    <a href="{{ route('admin.claims.show', $claim) }}" class="hover:underline">{{ $claim->documents_count }} document(s)</a>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('admin.claims.edit', $claim) }}" class="text-xs text-brand-600 hover:underline">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No claims yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $claims->withQueryString()->links() }}
        </div>
    </div>
</x-app-layout>
