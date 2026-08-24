<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Approvals Inbox') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Document</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Owner</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Stage</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Assigned</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($pending as $assignee)
                            <tr class="hover:bg-gray-50 {{ $assignee->isOverdue() ? 'bg-red-50/40' : '' }}">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900 flex items-center gap-1.5">
                                        @if ($assignee->isOverdue())
                                            <span title="Overdue — waiting {{ $assignee->hoursWaiting() }}h (SLA {{ $assignee->slaHours() }}h)" class="text-red-600">🚩</span>
                                        @endif
                                        {{ $assignee->instance->document->title }}
                                    </div>
                                    <div class="text-xs text-gray-500 font-mono">{{ $assignee->instance->document->reference_no }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $assignee->instance->document->owner?->name }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $assignee->stage->name }}</td>
                                <td class="px-4 py-3 {{ $assignee->isOverdue() ? 'text-red-600 font-medium' : 'text-gray-500' }}">{{ $assignee->assigned_at?->diffForHumans() }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('documents.show', $assignee->instance->document_id) }}" class="inline-flex items-center px-3 py-1.5 bg-brand-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-700">
                                        Review
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">Nothing pending your approval.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $pending->links() }}
        </div>
    </div>
</x-app-layout>
