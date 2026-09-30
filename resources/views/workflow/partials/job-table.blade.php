{{-- A task owner's in-flight jobs and who holds each one right now. --}}
@if ($jobs->isEmpty())
    <div class="bg-white rounded-xl border border-gray-200 p-10 text-center text-sm text-gray-500">{{ $empty }}</div>
@else
    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm divide-y divide-gray-100">
            <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 font-medium">Job</th>
                    <th class="px-4 py-2.5 font-medium">Collateral</th>
                    <th class="px-4 py-2.5 font-medium">Status</th>
                    <th class="px-4 py-2.5 font-medium">With</th>
                    <th class="px-4 py-2.5 font-medium">Due</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($jobs as $doc)
                    @php
                        $pending = $doc->activeWorkflowInstance?->pendingAssignees ?? collect();
                        $work = $doc->workTasks->first();
                        $nextDue = $pending->map->dueAt()->filter()->sort()->first() ?? $work?->due_at;
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('documents.show', $doc) }}" class="font-medium text-gray-900 hover:text-brand-700">{{ $doc->title }}</a>
                            <div class="text-xs text-gray-500 font-mono">{{ $doc->reference_no }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600 text-xs">
                            {{ $doc->channelLabel() }}{{ $doc->channelLabel() && $doc->documentType ? ' · ' : '' }}{{ $doc->documentType?->name }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($doc->is_placeholder)
                                <x-status-badge status="draft">Placeholder</x-status-badge>
                            @else
                                <x-status-badge :status="$doc->status">{{ $doc->statusLabel() }}</x-status-badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-700">
                            @if ($work)
                                Design Team{{ $work->assignee ? ' — ' . $work->assignee->name : ' (unassigned)' }}
                            @elseif ($pending->isNotEmpty())
                                @foreach ($pending->groupBy('workflow_stage_id') as $group)
                                    <div><span class="text-gray-400">{{ $group->first()->stage->name }}:</span> {{ $group->pluck('user.name')->join(', ') }}</div>
                                @endforeach
                            @elseif ($doc->status === 'approved_with_changes_pending')
                                <span class="text-amber-700">Task owner — revision needed</span>
                            @elseif ($doc->status === 'draft')
                                <span class="text-gray-500">Task owner — not yet submitted</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap {{ $nextDue && $nextDue->isPast() ? 'text-red-600 font-medium' : 'text-gray-500' }}">
                            {{ $nextDue ? $nextDue->format('d M, H:i') : ($doc->due_date ? $doc->due_date->format('d M Y') : '—') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
