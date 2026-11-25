<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('admin._nav')

            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <a href="{{ route('admin.content-agencies.index') }}" class="text-xs text-gray-500 hover:underline">&larr; All content agencies</a>
                    <h3 class="text-lg font-semibold text-gray-900 mt-1">{{ $agency->name }}
                        <span @class(['ml-1 align-middle text-xs px-2 py-0.5 rounded-full font-medium', 'bg-green-100 text-green-700' => $agency->status === 'active', 'bg-gray-100 text-gray-500' => $agency->status !== 'active'])>{{ ucfirst($agency->status) }}</span>
                    </h3>
                </div>
                <form method="POST" action="{{ route('admin.content-agencies.toggle-status', $agency) }}">
                    @csrf
                    <button class="text-xs px-3 py-1.5 border border-gray-200 rounded-md text-gray-600 hover:bg-gray-50">{{ $agency->status === 'active' ? 'Deactivate agency' : 'Activate agency' }}</button>
                </form>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h4 class="text-sm font-semibold text-gray-900 mb-3">Agency details</h4>
                <form method="POST" action="{{ route('admin.content-agencies.update', $agency) }}" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                    @csrf @method('PUT')
                    <div>
                        <x-input-label for="agency_name" value="Name" />
                        <x-text-input id="agency_name" name="name" class="mt-1 block w-full" required :value="old('name', $agency->name)" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="contact_email" value="Contact email" />
                        <x-text-input type="email" id="contact_email" name="contact_email" class="mt-1 block w-full" :value="old('contact_email', $agency->contact_email)" />
                    </div>
                    <div>
                        <x-input-label for="contact_phone" value="Contact phone" />
                        <x-text-input id="contact_phone" name="contact_phone" class="mt-1 block w-full" :value="old('contact_phone', $agency->contact_phone)" />
                    </div>
                    <div class="md:col-span-3"><x-primary-button>Save</x-primary-button></div>
                </form>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden">
                <div class="px-6 pt-5 pb-3">
                    <h4 class="text-sm font-semibold text-gray-900">Users ({{ $agency->users->count() }})</h4>
                    <p class="text-xs text-gray-500">Each has their own login and the Content Agency role.</p>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($agency->users as $member)
                            <tr>
                                <td class="px-6 py-3 font-medium text-gray-900">{{ $member->name }}</td>
                                <td class="px-6 py-3 text-gray-500">{{ $member->email }}</td>
                                <td class="px-6 py-3 text-gray-500 text-xs">{{ $member->designation }}</td>
                                <td class="px-6 py-3">
                                    <span @class(['text-xs px-2 py-0.5 rounded-full font-medium', 'bg-green-100 text-green-700' => $member->is_active, 'bg-gray-100 text-gray-500' => ! $member->is_active])>{{ $member->is_active ? 'Active' : 'Inactive' }}</span>
                                </td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">
                                    <form method="POST" action="{{ route('admin.users.toggle-active', $member) }}" class="inline">
                                        @csrf
                                        <button class="text-xs text-brand-600 hover:underline mr-2">{{ $member->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.content-agencies.users.detach', [$agency, $member]) }}" class="inline" onsubmit="return confirm('Remove {{ $member->name }} from {{ $agency->name }}?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-gray-400 hover:text-red-600">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="px-6 py-4 text-gray-500">No users yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h4 class="text-sm font-semibold text-gray-900 mb-3">Create a user</h4>
                    <form method="POST" action="{{ route('admin.content-agencies.users.store', $agency) }}" class="space-y-3">
                        @csrf
                        <div>
                            <x-input-label for="user_name" value="Name" />
                            <x-text-input id="user_name" name="name" class="mt-1 block w-full" required :value="old('name')" />
                        </div>
                        <div>
                            <x-input-label for="user_email" value="Email (login)" />
                            <x-text-input type="email" id="user_email" name="email" class="mt-1 block w-full" required :value="old('email')" />
                            <x-input-error :messages="$errors->get('email')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="user_designation" value="Designation (optional)" />
                            <x-text-input id="user_designation" name="designation" class="mt-1 block w-full" :value="old('designation')" />
                        </div>
                        <p class="text-xs text-gray-500">A temporary password is shown once created; they change it on first login.</p>
                        <x-primary-button>Create user</x-primary-button>
                    </form>
                </div>

                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h4 class="text-sm font-semibold text-gray-900 mb-3">Add an existing user</h4>
                    @if ($attachable->isEmpty())
                        <p class="text-sm text-gray-500">Every Content Agency user already belongs to an agency.</p>
                    @else
                        <form method="POST" action="{{ route('admin.content-agencies.users.attach', $agency) }}" class="space-y-3">
                            @csrf
                            <select name="user_id" required class="block w-full text-sm border-gray-300 rounded-md">
                                @foreach ($attachable as $candidate)
                                    <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</option>
                                @endforeach
                            </select>
                            <x-primary-button>Add to {{ $agency->name }}</x-primary-button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h4 class="text-sm font-semibold text-gray-900 mb-3">Open jobs with this agency ({{ $openTasks->count() }})</h4>
                @forelse ($openTasks as $task)
                    <div class="flex items-center justify-between py-2 border-t border-gray-100 first:border-t-0 text-sm">
                        <a href="{{ route('documents.show', $task->document_id) }}" class="text-gray-900 hover:text-brand-700">{{ $task->document?->title }}</a>
                        <span class="text-xs text-gray-500">{{ $task->typeLabel() }} · {{ $task->assignee?->name ?? 'unassigned' }}{{ $task->due_at ? ' · due ' . $task->due_at->format('d M, H:i') : '' }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Nothing with them right now.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
