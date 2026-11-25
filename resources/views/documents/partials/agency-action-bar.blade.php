{{--
    Right-side action bar for Content Agency logins (MOM 24 Nov: redesigned). The
    agency sees what it needs to do the work - the brief, the due date, who it is
    with, the upload, reference files and the reviewers' comments - and nothing of
    the approval workflow around the job.
--}}
@php
    $agencyTask = $document->workTasks
        ->where('status', 'open')
        ->where('team', 'agency')
        ->first(fn ($t) => $t->isForTeamOf($user));
    $colleagues = $agencyTask ? $agencyTask->teamMembers()->where('id', '!=', $user->id)->get(['id', 'name']) : collect();
    $isMine = $agencyTask && $agencyTask->assigned_to === $user->id;
    $canUploadTask = $agencyTask && ($isMine || $agencyTask->assigned_to === null);
@endphp

@if ($agencyTask)
    <div class="rounded-xl overflow-hidden border border-violet-200 bg-white shadow-sm" x-data="{ panel: '{{ $canUploadTask ? 'upload' : '' }}' }">
        <div class="px-5 py-4 bg-gradient-to-br from-violet-600 to-violet-500 text-white">
            <p class="text-[11px] uppercase tracking-wider text-violet-100">{{ $agencyTask->contentAgency?->name ?? 'Content Agency' }}</p>
            <h3 class="text-base font-semibold leading-snug">{{ $agencyTask->typeLabel() }}</h3>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                @if ($agencyTask->due_at)
                    <span class="px-2 py-0.5 rounded-full {{ $agencyTask->isOverdue() ? 'bg-red-500 text-white' : 'bg-white/20' }}">
                        {{ $agencyTask->isOverdue() ? 'Overdue · ' : 'Due ' }}{{ $agencyTask->due_at->format('d M Y, H:i') }} IST
                    </span>
                @endif
                <span class="px-2 py-0.5 rounded-full bg-white/20">{{ $isMine ? 'Assigned to you' : ($agencyTask->assignee ? 'With ' . $agencyTask->assignee->name : 'Not yet taken') }}</span>
            </div>
        </div>

        <div class="p-5 space-y-4">
            <div>
                <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Brief from {{ $agencyTask->requester?->name ?? 'the Brand Manager' }}</p>
                <p class="text-sm text-gray-700 whitespace-pre-line">{{ $agencyTask->instructions ?: 'No written brief — see the current version and the reference files.' }}</p>
            </div>

            <div class="grid grid-cols-2 gap-2">
                @if (! $isMine)
                    <form method="POST" action="{{ route('work-tasks.take', $agencyTask) }}">
                        @csrf
                        <button class="w-full px-3 py-2 rounded-lg border border-violet-200 text-sm font-medium text-violet-700 hover:bg-violet-50">Take it</button>
                    </form>
                @endif
                @if ($colleagues->isNotEmpty())
                    <button type="button" @click="panel = panel === 'reassign' ? '' : 'reassign'" :class="panel === 'reassign' ? 'bg-gray-100' : ''"
                            class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm font-medium text-gray-700 hover:bg-gray-50 {{ $isMine ? 'col-span-1' : '' }}">Reassign</button>
                @endif
                @if ($canUploadTask)
                    <button type="button" @click="panel = panel === 'upload' ? '' : 'upload'" :class="panel === 'upload' ? 'ring-2 ring-violet-300' : ''"
                            class="w-full px-3 py-2 rounded-lg bg-violet-600 text-sm font-semibold text-white hover:bg-violet-700 {{ $isMine && $colleagues->isEmpty() ? 'col-span-2' : '' }}">Upload file</button>
                @endif
            </div>

            @if ($colleagues->isNotEmpty())
                <form x-show="panel === 'reassign'" x-cloak method="POST" action="{{ route('work-tasks.assign', $agencyTask) }}" class="p-3 bg-gray-50 rounded-lg space-y-2">
                    @csrf
                    <label class="text-xs text-gray-500">Hand it to a colleague at {{ $agencyTask->contentAgency?->name ?? 'your agency' }}</label>
                    <x-search-select placeholder="Search a colleague…">
                        <select name="user_id" required>
                            <option value="">Select…</option>
                            @foreach ($colleagues as $colleague)
                                <option value="{{ $colleague->id }}">{{ $colleague->name }}</option>
                            @endforeach
                        </select>
                    </x-search-select>
                    <x-input-error :messages="$errors->get('assign')" class="mt-1" />
                    <button class="text-xs font-medium text-white bg-gray-800 rounded-md px-3 py-1.5">Reassign</button>
                </form>
            @endif

            @if ($canUploadTask)
                <form x-show="panel === 'upload'" x-cloak method="POST" action="{{ route('work-tasks.complete', $agencyTask) }}" enctype="multipart/form-data" class="p-3 bg-violet-50/60 border border-violet-100 rounded-lg space-y-2">
                    @csrf
                    <x-file-dropzone name="file" required hint="Your artwork / revised file." />
                    <input type="text" name="notes" placeholder="Notes for the Brand Manager (optional)" class="block w-full text-sm border-gray-300 rounded-md">
                    <x-input-error :messages="$errors->get('file')" class="mt-1" />
                    <button class="w-full px-3 py-2 rounded-lg bg-violet-600 text-sm font-semibold text-white hover:bg-violet-700">Upload &amp; send to Brand Manager</button>
                    <p class="text-[11px] text-gray-500">The Brand Manager checks your upload. Once sent, this job leaves your workspace.</p>
                </form>
            @elseif (! $isMine && $agencyTask->assignee)
                <p class="text-xs text-gray-500">{{ $agencyTask->assignee->name }} is working on this. Take it to upload yourself.</p>
            @endif
        </div>
    </div>
@endif

<div class="bg-white rounded-xl border border-gray-200/80 p-5">
    <h3 class="text-sm font-semibold text-gray-900 mb-2">Reference files ({{ $document->referenceAttachments->count() }})</h3>
    @forelse ($document->referenceAttachments as $reference)
        <div class="flex items-center justify-between gap-2 py-2 border-t border-gray-100 first:border-t-0 text-sm">
            <span class="min-w-0 truncate text-gray-800" title="{{ $reference->original_filename }}">{{ $reference->title }}</span>
            <a href="{{ $reference->downloadUrl() }}" class="shrink-0 text-xs font-medium text-brand-600 hover:underline">Download</a>
        </div>
    @empty
        <p class="text-sm text-gray-400">None attached.</p>
    @endforelse
</div>

<div class="bg-white rounded-xl border border-gray-200/80 p-5">
    <h3 class="text-sm font-semibold text-gray-900 mb-1">Reviewers' comments</h3>
    <p class="text-[11px] text-gray-400 mb-3">Version by version, including comments inside the Word file.</p>
    <div class="max-h-[32rem] overflow-y-auto">
        @include('documents.partials.comments-by-version', ['readOnly' => true])
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200/80 p-5 text-sm">
    <h3 class="text-sm font-semibold text-gray-900 mb-1">Brand Manager</h3>
    <p class="text-gray-700 flex items-center gap-1.5"><x-avatar :name="$document->owner?->name" size="xs" />{{ $document->owner?->name }}</p>
    <p class="text-xs text-gray-400 mt-0.5">{{ $document->owner?->email }}</p>
</div>
