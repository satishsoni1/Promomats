<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Helpdesk</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-6 h-fit">
                <h3 class="text-base font-semibold text-gray-900">Raise a ticket</h3>
                <p class="text-xs text-gray-500 mt-1 mb-4">For technical glitches, change requests or access problems. Support is emailed immediately and you'll get an acknowledgement with the first-response time.</p>
                <form method="POST" action="{{ route('helpdesk.store') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="page_url" value="{{ $prefill['page_url'] }}">
                    @if ($prefill['document_id'])
                        <input type="hidden" name="document_id" value="{{ $prefill['document_id'] }}">
                    @endif
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="category" value="Type" />
                            <select id="category" name="category" class="mt-1 block w-full text-sm border-gray-300 rounded-md">
                                @foreach (\App\Models\SupportTicket::CATEGORIES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="priority" value="Priority" />
                            <select id="priority" name="priority" class="mt-1 block w-full text-sm border-gray-300 rounded-md">
                                @foreach (\App\Models\SupportTicket::PRIORITIES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('priority', 'normal') === $key)>{{ $label }} — reply within {{ config('promomats.helpdesk.first_response_hours')[$key] }}h</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <x-input-label for="subject" value="Subject" />
                        <x-text-input id="subject" name="subject" class="mt-1 block w-full text-sm" :value="old('subject')" required />
                        <x-input-error :messages="$errors->get('subject')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="description" value="What happened?" />
                        <textarea id="description" name="description" rows="5" required class="mt-1 block w-full text-sm border-gray-300 rounded-md" placeholder="Steps, document reference, what you expected…">{{ old('description') }}</textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>
                    <x-primary-button>Submit ticket</x-primary-button>
                </form>
            </div>

            <div class="lg:col-span-3 bg-white rounded-xl border border-gray-200 overflow-hidden h-fit">
                <h3 class="px-5 py-3 text-base font-semibold text-gray-900 border-b border-gray-100">{{ auth()->user()->can('access-admin') ? 'All tickets' : 'My tickets' }}</h3>
                <div class="divide-y divide-gray-100">
                    @forelse ($tickets as $ticket)
                        <div class="px-5 py-3 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900">{{ $ticket->subject }}</p>
                                    <p class="text-xs text-gray-500">
                                        <span class="font-mono">{{ $ticket->reference }}</span>
                                        · {{ \App\Models\SupportTicket::CATEGORIES[$ticket->category] ?? $ticket->category }}
                                        · {{ ucfirst($ticket->priority) }}
                                        @can('access-admin') · {{ $ticket->user?->name }} @endcan
                                        · {{ $ticket->created_at->diffForHumans() }}
                                    </p>
                                </div>
                                @can('access-admin')
                                    <form method="POST" action="{{ route('helpdesk.update', $ticket) }}">
                                        @csrf @method('PATCH')
                                        <select name="status" onchange="this.form.submit()" class="text-xs border-gray-300 rounded-md py-1">
                                            @foreach (['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $key => $label)
                                                <option value="{{ $key }}" @selected($ticket->status === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @else
                                    <span class="text-xs px-2 py-0.5 rounded-full {{ in_array($ticket->status, ['resolved', 'closed']) ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ str_replace('_', ' ', ucfirst($ticket->status)) }}</span>
                                @endcan
                            </div>
                            @if (! in_array($ticket->status, ['resolved', 'closed']))
                                <p class="text-xs mt-1 {{ $ticket->first_response_due_at?->isPast() ? 'text-red-600' : 'text-gray-400' }}">First response due {{ $ticket->first_response_due_at?->format('d M, H:i') }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-gray-500">No tickets yet.</p>
                    @endforelse
                </div>
                <div class="px-5 py-3">{{ $tickets->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
