@php
    $tabs = [
        'action' => ['Needs my action', $counts['action']],
        'mine' => ['My jobs', $counts['mine']],
    ];
    if ($designQueue->isNotEmpty() || auth()->user()->isDesignTeam()) {
        $tabs['design'] = ['Design Team queue', $counts['design']];
    }
    if ($brandManagers->isNotEmpty()) {
        $tabs['bm'] = [auth()->user()->canAdminister() ? 'Brand Managers' : 'My Brand Manager view', null];
    }
    $due = function ($at) {
        if (! $at) return ['—', 'text-gray-400'];
        if ($at->isPast()) return ['Overdue ' . $at->diffForHumans(null, true), 'text-red-600 font-medium'];
        if (now()->diffInHours($at) <= config('promomats.due_dates.reminder_hours_before')) return ['Due in ' . $at->diffForHumans(null, true), 'text-amber-600 font-medium'];
        return ['Due ' . $at->format('d M, H:i'), 'text-gray-500'];
    };
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Active Workflow</h2>
            @can('create', \App\Models\Document::class)
                <a href="{{ route('documents.create') }}" class="inline-flex items-center px-3 py-2 bg-brand-600 rounded-lg text-sm font-medium text-white hover:bg-brand-700">+ New task</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

            <nav class="flex flex-wrap gap-1 border-b border-gray-200">
                @foreach ($tabs as $key => [$label, $count])
                    <a href="{{ route('workflow.active', ['tab' => $key]) }}"
                       class="px-3 py-2 text-sm font-medium border-b-2 -mb-px {{ $tab === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">
                        {{ $label }}
                        @if ($count)
                            <span class="ml-1 text-[11px] px-1.5 py-0.5 rounded-full {{ $tab === $key ? 'bg-brand-100 text-brand-700' : 'bg-gray-100 text-gray-600' }}">{{ $count }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>

            {{-- ---------------- Needs my action ---------------- --}}
            @if ($tab === 'action')
                @if ($counts['action'] === 0)
                    <div class="bg-white rounded-xl border border-gray-200 p-10 text-center text-sm text-gray-500">Nothing is waiting on you right now.</div>
                @endif

                @if ($myApprovals->isNotEmpty())
                    <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <h3 class="px-4 py-3 text-sm font-semibold text-gray-900 border-b border-gray-100">Review / approve ({{ $myApprovals->count() }})</h3>
                        <div class="divide-y divide-gray-100">
                            @foreach ($myApprovals as $task)
                                @php [$dueText, $dueClass] = $due($task->dueAt()); $doc = $task->instance->document; @endphp
                                <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 {{ $task->isOverdue() ? 'bg-red-50/40' : '' }}">
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('documents.show', $doc) }}" class="font-medium text-gray-900 hover:text-brand-700">{{ $doc->title }}</a>
                                        <div class="text-xs text-gray-500 flex flex-wrap gap-x-2">
                                            <span class="font-mono">{{ $doc->reference_no }}</span>
                                            <span>· {{ $task->stage->name }}</span>
                                            @if ($doc->documentType)<span>· {{ $doc->documentType->name }}</span>@endif
                                            <span>· Task owner {{ $doc->owner?->name }}</span>
                                            @if ($task->reassigned_from_id)<span class="text-brand-600">· reassigned to you</span>@endif
                                        </div>
                                    </div>
                                    <span class="text-xs {{ $dueClass }} whitespace-nowrap">{{ $dueText }}</span>
                                    <a href="{{ route('documents.show', $doc) }}" class="inline-flex justify-center px-3 py-1.5 bg-brand-600 rounded-md text-xs font-semibold text-white hover:bg-brand-700">{{ $task->stage->isDraft() ? 'Open draft' : 'Review' }}</a>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($myRevisions->isNotEmpty())
                    <section class="bg-white rounded-xl border border-amber-200 overflow-hidden">
                        <h3 class="px-4 py-3 text-sm font-semibold text-gray-900 border-b border-amber-100 bg-amber-50/50">Revisions waiting on you ({{ $myRevisions->count() }})</h3>
                        <div class="divide-y divide-gray-100">
                            @foreach ($myRevisions as $doc)
                                @php $lastAction = $doc->approvalActions->first(); @endphp
                                <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('documents.show', $doc) }}" class="font-medium text-gray-900 hover:text-brand-700">{{ $doc->title }}</a>
                                        <div class="text-xs text-gray-500">
                                            <span class="font-mono">{{ $doc->reference_no }}</span>
                                            @if ($lastAction)
                                                · {{ $lastAction->decisionLabel() }} by {{ $lastAction->actor?->name }} at {{ $lastAction->stage?->name }}, {{ $lastAction->acted_at?->diffForHumans() }}
                                            @endif
                                        </div>
                                    </div>
                                    <a href="{{ route('documents.show', $doc) }}#revise" class="inline-flex justify-center px-3 py-1.5 bg-amber-600 rounded-md text-xs font-semibold text-white hover:bg-amber-700">Upload revision / send to Design</a>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($myWorkTasks->isNotEmpty())
                    <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <h3 class="px-4 py-3 text-sm font-semibold text-gray-900 border-b border-gray-100">Design work ({{ $myWorkTasks->count() }})</h3>
                        @include('workflow.partials.work-task-rows', ['tasks' => $myWorkTasks])
                    </section>
                @endif
            @endif

            {{-- ---------------- My jobs ---------------- --}}
            @if ($tab === 'mine')
                @include('workflow.partials.job-table', ['jobs' => $myJobs, 'empty' => 'You have no jobs in flight.'])
            @endif

            {{-- ---------------- Design Team queue ---------------- --}}
            @if ($tab === 'design')
                <section class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-900">Design Team queue</h3>
                        <p class="text-xs text-gray-500">Work sent to the whole team. Take it yourself or assign it to a designer based on bandwidth.</p>
                    </div>
                    @if ($designQueue->isEmpty())
                        <p class="px-4 py-8 text-center text-sm text-gray-500">The queue is empty.</p>
                    @else
                        @include('workflow.partials.work-task-rows', ['tasks' => $designQueue])
                    @endif
                </section>
            @endif

            {{-- ---------------- Brand Manager views ---------------- --}}
            @if ($tab === 'bm' && $selectedBm)
                @if ($brandManagers->count() > 1)
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($brandManagers as $bm)
                            <a href="{{ route('workflow.active', ['tab' => 'bm', 'bm' => $bm->id]) }}"
                               class="px-3 py-1.5 rounded-full text-xs font-medium border {{ $selectedBm->id === $bm->id ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-600 border-gray-200 hover:border-brand-300' }}">{{ $bm->name }}</a>
                        @endforeach
                    </div>
                @endif
                <p class="text-sm text-gray-600">Active workflow for <strong>{{ $selectedBm->name }}</strong></p>
                @include('workflow.partials.job-table', ['jobs' => $bmJobs, 'empty' => "{$selectedBm->name} has no jobs in flight."])
            @endif
        </div>
    </div>
</x-app-layout>
