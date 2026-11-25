{{-- Team work tasks (internal Design Team / Content Agency) with take / assign / upload controls. Expects $tasks. --}}
@php
    $me = auth()->user();
    $membersByTeam = [];
@endphp
<div class="divide-y divide-gray-100">
    @foreach ($tasks as $task)
        @php
            // Agency work goes only to the same agency's people (MOM 24 Nov).
            $inTeam = $task->isForTeamOf($me);
            $canUpload = $task->assigned_to === $me->id || ($task->assigned_to === null && $inTeam);
            $canAssign = $inTeam || $me->can('manageTasks', $task->document);
            $teamKey = $task->team . ':' . ($task->team === 'agency' ? $task->content_agency_id : '');
            if ($canAssign && ! isset($membersByTeam[$teamKey])) {
                $membersByTeam[$teamKey] = $task->teamMembers()->get(['id', 'name']);
            }
            $members = $canAssign ? $membersByTeam[$teamKey] : collect();
        @endphp
        <div class="px-4 py-3 space-y-2 {{ $task->isOverdue() ? 'bg-red-50/40' : '' }}" x-data="{ upload: false }">
            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
                <div class="min-w-0 flex-1">
                    <a href="{{ route('documents.show', $task->document_id) }}" class="font-medium text-gray-900 hover:text-brand-700">{{ $task->document->title }}</a>
                    <div class="text-xs text-gray-500 flex flex-wrap gap-x-2">
                        <span class="font-mono">{{ $task->document->reference_no }}</span>
                        <span>· {{ $task->typeLabel() }}</span>
                        <span>· {{ $task->teamLabel() }}</span>
                        <span>· from {{ $task->requester?->name }}</span>
                        <span>· {{ $task->assignee ? 'Assigned to ' . $task->assignee->name : 'Unassigned (whole team)' }}</span>
                    </div>
                    @if ($task->instructions)
                        <p class="text-xs text-gray-700 mt-1 whitespace-pre-line">{{ $task->instructions }}</p>
                    @endif
                </div>
                <span class="text-xs whitespace-nowrap {{ $task->isOverdue() ? 'text-red-600 font-medium' : 'text-gray-500' }}">{{ $task->due_at ? 'Due ' . $task->due_at->format('d M, H:i') : '' }}</span>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($inTeam && $task->assigned_to !== $me->id)
                        <form method="POST" action="{{ route('work-tasks.take', $task) }}">
                            @csrf
                            <button class="px-2.5 py-1.5 border border-gray-200 rounded-md text-xs text-gray-700 hover:bg-gray-50">Take it</button>
                        </form>
                    @endif
                    @if ($members->isNotEmpty())
                        <form method="POST" action="{{ route('work-tasks.assign', $task) }}" class="flex items-center gap-1">
                            @csrf
                            <x-search-select size="sm" class="w-48" placeholder="{{ $task->assigned_to ? 'Reassign to…' : 'Assign to…' }}">
                                <select name="user_id" onchange="this.form.submit()">
                                    <option value="">{{ $task->assigned_to ? 'Reassign to…' : 'Assign to…' }}</option>
                                    @foreach ($members as $member)
                                        <option value="{{ $member->id }}" @selected($task->assigned_to === $member->id)>{{ $member->name }}</option>
                                    @endforeach
                                </select>
                            </x-search-select>
                        </form>
                    @endif
                    @if ($canUpload)
                        <button type="button" @click="upload = ! upload" class="px-2.5 py-1.5 bg-brand-600 rounded-md text-xs font-semibold text-white hover:bg-brand-700">Upload file</button>
                    @endif
                </div>
            </div>
            @if ($canUpload)
                <form x-show="upload" x-cloak method="POST" action="{{ route('work-tasks.complete', $task) }}" enctype="multipart/form-data" class="p-3 bg-gray-50 rounded-lg space-y-2">
                    @csrf
                    <x-file-dropzone name="file" required />
                    <input type="text" name="notes" placeholder="Notes for the Brand Manager (optional)" class="block w-full text-sm border-gray-300 rounded-md">
                    <x-primary-button>Upload &amp; send to Brand Manager</x-primary-button>
                    <p class="text-[11px] text-gray-400">The Brand Manager checks your upload before it goes for review.</p>
                </form>
            @endif
        </div>
    @endforeach
</div>
