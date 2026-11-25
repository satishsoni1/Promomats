<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="mb-3">
                <h3 class="text-base font-semibold text-gray-900">Content Agencies</h3>
                <p class="text-xs text-gray-500">External agencies and their users. Agency users sign in with their own login and see only the jobs a Brand Manager sends to their agency — never the workflow around them — and can hand work only to colleagues in the same agency.</p>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden mb-6">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Agency</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Contact</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Users</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Open jobs</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($agencies as $agency)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-900"><a href="{{ route('admin.content-agencies.show', $agency) }}" class="hover:text-brand-700">{{ $agency->name }}</a></td>
                                <td class="px-4 py-3 text-gray-500 text-xs">{{ collect([$agency->contact_email, $agency->contact_phone])->filter()->join(' · ') ?: '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $agency->users_count }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $agency->open_tasks_count }}</td>
                                <td class="px-4 py-3">
                                    <span @class(['text-xs px-2 py-0.5 rounded-full font-medium', 'bg-green-100 text-green-700' => $agency->status === 'active', 'bg-gray-100 text-gray-500' => $agency->status !== 'active'])>{{ ucfirst($agency->status) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.content-agencies.show', $agency) }}" class="text-xs text-brand-600 hover:underline">Manage users</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No content agencies yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($unassignedAgencyUsers->isNotEmpty())
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 mb-6 text-sm text-amber-900">
                    <strong>{{ $unassignedAgencyUsers->count() }} Content Agency user(s) not under any agency:</strong>
                    {{ $unassignedAgencyUsers->pluck('name')->join(', ') }}. Open an agency and use "Add an existing user" so their work is scoped to it.
                </div>
            @endif

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Add Content Agency</h3>
                <form method="POST" action="{{ route('admin.content-agencies.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                    @csrf
                    <div>
                        <x-input-label for="name" value="Agency name" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" required :value="old('name')" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="contact_email" value="Contact email (optional)" />
                        <x-text-input type="email" id="contact_email" name="contact_email" class="mt-1 block w-full" :value="old('contact_email')" />
                    </div>
                    <div>
                        <x-input-label for="contact_phone" value="Contact phone (optional)" />
                        <x-text-input id="contact_phone" name="contact_phone" class="mt-1 block w-full" :value="old('contact_phone')" />
                    </div>
                    <div class="md:col-span-3"><x-primary-button>Add agency</x-primary-button></div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
