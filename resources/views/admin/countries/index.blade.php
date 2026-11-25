<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Countries</h3>
                    <p class="text-xs text-gray-500">The Country dropdown on every new job. The code (e.g. IN) prefixes the job's reference number.</p>
                </div>
                <span class="text-xs text-gray-400">{{ $countries->where('status', 'active')->count() }} active</span>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden mb-6">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Country</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Code</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Documents</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($countries as $country)
                            <tr x-data="{ edit: false }">
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    <span x-show="! edit">{{ $country->name }}</span>
                                    <form x-show="edit" x-cloak method="POST" action="{{ route('admin.countries.update', $country) }}" class="flex flex-wrap items-center gap-2">
                                        @csrf @method('PUT')
                                        <input name="name" value="{{ $country->name }}" required class="text-sm border-gray-300 rounded-md py-1 w-40">
                                        <input name="code" value="{{ $country->code }}" placeholder="Code" class="text-sm border-gray-300 rounded-md py-1 w-20 uppercase">
                                        <button class="text-xs font-medium text-white bg-brand-600 rounded-md px-2.5 py-1">Save</button>
                                        <button type="button" @click="edit = false" class="text-xs text-gray-500">Cancel</button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $country->code ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $country->documents_count }}</td>
                                <td class="px-4 py-3">
                                    <span @class(['text-xs px-2 py-0.5 rounded-full font-medium', 'bg-green-100 text-green-700' => $country->status === 'active', 'bg-gray-100 text-gray-500' => $country->status !== 'active'])>{{ ucfirst($country->status) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <button type="button" @click="edit = ! edit" class="text-xs text-gray-500 hover:underline mr-2">Edit</button>
                                    <form method="POST" action="{{ route('admin.countries.toggle-status', $country) }}" class="inline">
                                        @csrf
                                        <button class="text-xs text-brand-600 hover:underline">{{ $country->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No countries yet — add them below, or paste Himalaya's list with Bulk add.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Add Country</h3>
                    <form method="POST" action="{{ route('admin.countries.store') }}" class="space-y-3">
                        @csrf
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" name="name" class="mt-1 block w-full" required placeholder="e.g. India" :value="old('name')" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="code" value="Code (optional)" />
                            <x-text-input id="code" name="code" class="mt-1 block w-full uppercase" placeholder="e.g. IN" :value="old('code')" />
                            <x-input-error :messages="$errors->get('code')" class="mt-1" />
                        </div>
                        <x-primary-button>Add</x-primary-button>
                    </form>
                </div>

                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">Bulk add</h3>
                    <p class="text-xs text-gray-500 mb-3">One country per line — <code>Name</code> or <code>Name, Code</code>. Countries already in the list are skipped.</p>
                    <form method="POST" action="{{ route('admin.countries.bulk') }}" class="space-y-3">
                        @csrf
                        <textarea name="lines" rows="6" required class="block w-full text-sm font-mono border-gray-300 rounded-md" placeholder="India, IN&#10;Nepal, NP&#10;Sri Lanka, LK"></textarea>
                        <x-input-error :messages="$errors->get('lines')" class="mt-1" />
                        <x-primary-button>Import</x-primary-button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
