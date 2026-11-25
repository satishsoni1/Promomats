<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">SKUs</h3>
                    <p class="text-xs text-gray-500">Each SKU belongs to a brand. On a new job the SKU dropdown shows only the selected brand's SKUs.</p>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    <x-search-select class="w-52" placeholder="All brands (search)…">
                        <select name="brand" onchange="this.form.submit()">
                            <option value="">All brands</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}" @selected(request('brand') == $brand->id)>{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </x-search-select>
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="Search SKU…" class="text-sm border-gray-300 rounded-md py-1.5 w-40">
                </form>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-x-auto mb-4">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Brand</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">SKU</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Code</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Documents</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($skus as $sku)
                            <tr x-data="{ edit: false }">
                                <td class="px-4 py-3 text-gray-600">{{ $sku->brand?->name }}</td>
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    <span x-show="! edit">{{ $sku->name }}</span>
                                    <form x-show="edit" x-cloak method="POST" action="{{ route('admin.skus.update', $sku) }}" class="flex flex-wrap items-center gap-2">
                                        @csrf @method('PUT')
                                        <select name="brand_id" class="text-sm border-gray-300 rounded-md py-1">
                                            @foreach ($brands as $brand)
                                                <option value="{{ $brand->id }}" @selected($sku->brand_id === $brand->id)>{{ $brand->name }}</option>
                                            @endforeach
                                        </select>
                                        <input name="name" value="{{ $sku->name }}" required class="text-sm border-gray-300 rounded-md py-1 w-40">
                                        <input name="code" value="{{ $sku->code }}" placeholder="Code" class="text-sm border-gray-300 rounded-md py-1 w-24">
                                        <button class="text-xs font-medium text-white bg-brand-600 rounded-md px-2.5 py-1">Save</button>
                                        <button type="button" @click="edit = false" class="text-xs text-gray-500">Cancel</button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $sku->code ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $sku->documents_count }}</td>
                                <td class="px-4 py-3">
                                    <span @class(['text-xs px-2 py-0.5 rounded-full font-medium', 'bg-green-100 text-green-700' => $sku->status === 'active', 'bg-gray-100 text-gray-500' => $sku->status !== 'active'])>{{ ucfirst($sku->status) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <button type="button" @click="edit = ! edit" class="text-xs text-gray-500 hover:underline mr-2">Edit</button>
                                    <form method="POST" action="{{ route('admin.skus.toggle-status', $sku) }}" class="inline">
                                        @csrf
                                        <button class="text-xs text-brand-600 hover:underline">{{ $sku->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No SKUs yet — add them below, or paste Himalaya's Brand &amp; SKU list with Bulk add.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mb-6">{{ $skus->links() }}</div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Add SKU</h3>
                    @if ($brands->isEmpty())
                        <p class="text-sm text-gray-500">Add a <a href="{{ route('admin.brands.index') }}" class="text-brand-600 hover:underline">brand</a> first, or use Bulk add — it creates missing brands.</p>
                    @else
                        <form method="POST" action="{{ route('admin.skus.store') }}" class="space-y-3">
                            @csrf
                            <div>
                                <x-input-label for="brand_id" value="Brand" />
                                <x-search-select class="mt-1" placeholder="Search brand…" required>
                                    <select id="brand_id" name="brand_id" required>
                                        <option value="">Select…</option>
                                        @foreach ($brands as $brand)
                                            <option value="{{ $brand->id }}" @selected(old('brand_id', request('brand')) == $brand->id)>{{ $brand->name }}</option>
                                        @endforeach
                                    </select>
                                </x-search-select>
                            </div>
                            <div>
                                <x-input-label for="name" value="SKU" />
                                <x-text-input id="name" name="name" class="mt-1 block w-full" required placeholder="e.g. Liv.52 DS Tablets 60s" :value="old('name')" />
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="code" value="SKU code (optional)" />
                                <x-text-input id="code" name="code" class="mt-1 block w-full" :value="old('code')" />
                            </div>
                            <x-primary-button>Add</x-primary-button>
                        </form>
                    @endif
                </div>

                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">Bulk add</h3>
                    <p class="text-xs text-gray-500 mb-3">One SKU per line — <code>Brand, SKU</code> or <code>Brand, SKU, Code</code> (paste straight from Excel as CSV). Brands not yet in the system are created; SKUs already there are skipped.</p>
                    <form method="POST" action="{{ route('admin.skus.bulk') }}" class="space-y-3">
                        @csrf
                        <textarea name="lines" rows="6" required class="block w-full text-sm font-mono border-gray-300 rounded-md" placeholder="Liv.52, Liv.52 DS Tablets 60s&#10;Liv.52, Liv.52 Syrup 200ml, L52S200"></textarea>
                        <x-input-error :messages="$errors->get('lines')" class="mt-1" />
                        <x-primary-button>Import</x-primary-button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
