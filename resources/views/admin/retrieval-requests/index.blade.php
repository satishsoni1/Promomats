<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-1">Cold Storage Retrieval Requests</h3>
                <p class="text-sm text-gray-500">
                    Standard SLA is {{ \App\Models\RetrievalRequest::SLA_HOURS }} hours from request to restored file.
                    The scheduled job processes pending requests automatically every 10 minutes — "Process Now" below
                    is a manual override for urgent cases or demos.
                </p>
            </div>

            <div class="flex gap-2 mb-4">
                @foreach (['all' => 'All', 'pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'failed' => 'Failed'] as $key => $label)
                    <a href="{{ route('admin.retrieval-requests.index', ['status' => $key]) }}"
                       class="text-xs px-3 py-1.5 rounded-full border {{ $status === $key ? 'bg-brand-600 border-brand-600 text-white' : 'border-gray-200 text-gray-600 hover:border-brand-300' }}">
                        {{ $label }}
                        @if ($key !== 'all' && ($counts[$key] ?? 0) > 0)
                            <span class="ml-0.5">({{ $counts[$key] }})</span>
                        @endif
                    </a>
                @endforeach
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg divide-y divide-gray-100">
                @forelse ($requests as $req)
                    <div class="p-5 flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <a href="{{ route('documents.show', $req->document) }}" class="text-sm font-medium text-brand-700 hover:underline">{{ $req->document?->title ?? '(deleted document)' }}</a>
                            <div class="mt-1 text-xs text-gray-500">
                                Requested by {{ $req->requestedBy?->name }} &middot; {{ $req->requested_at?->diffForHumans() }}
                                &middot; SLA due {{ $req->sla_due_at?->diffForHumans() }}
                                @if ($req->isOverdue())
                                    <span class="text-red-600 font-medium">🚩 SLA {{ $req->status === 'completed' ? 'missed' : 'overdue' }}</span>
                                @endif
                            </div>
                            @if ($req->notes)
                                <p class="mt-1 text-xs text-gray-500">{{ $req->notes }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span @class([
                                'text-xs px-2 py-0.5 rounded-full font-medium',
                                'bg-gray-100 text-gray-600' => $req->status === 'pending',
                                'bg-amber-100 text-amber-700' => $req->status === 'in_progress',
                                'bg-green-100 text-green-700' => $req->status === 'completed',
                                'bg-red-100 text-red-700' => $req->status === 'failed',
                            ])>{{ $req->statusLabel() }}</span>
                            @if (in_array($req->status, ['pending', 'in_progress']))
                                <form method="POST" action="{{ route('admin.retrieval-requests.process', $req) }}">
                                    @csrf
                                    <button class="text-xs px-3 py-1.5 bg-brand-600 text-white rounded-md hover:bg-brand-700">Process Now</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="p-6 text-sm text-gray-500">No {{ $status === 'all' ? '' : str_replace('_', ' ', $status) . ' ' }}retrieval requests.</p>
                @endforelse
            </div>

            <div class="mt-4">{{ $requests->links() }}</div>
        </div>
    </div>
</x-app-layout>
