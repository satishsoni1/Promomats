<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Agency workspace</h2>
            <p class="text-xs text-gray-500 mt-0.5">{{ $user->contentAgency?->name ?? 'Content Agency' }} · jobs sent to you by the Brand Managers</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="grid grid-cols-3 gap-3">
                @foreach ([['Assigned to me', $mine->count(), 'text-brand-700'], ['Waiting to be taken', $unassigned->count(), 'text-amber-700'], ['With colleagues', $colleagues->count(), 'text-gray-700']] as [$label, $count, $tone])
                    <div class="bg-white rounded-xl border border-gray-200/80 p-4">
                        <p class="text-xs text-gray-500">{{ $label }}</p>
                        <p class="text-2xl font-semibold {{ $tone }}">{{ $count }}</p>
                    </div>
                @endforeach
            </div>

            @foreach ([['Assigned to me', $mine, 'Nothing assigned to you right now.'], ['Waiting to be taken', $unassigned, 'Nothing waiting — every job has someone on it.'], ['With colleagues', $colleagues, 'No jobs with your colleagues.']] as [$title, $tasks, $empty])
                <div class="bg-white rounded-xl border border-gray-200/80 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">{{ $title }}</h3>
                        <span class="text-xs text-gray-400">{{ $tasks->count() }}</span>
                    </div>
                    @if ($tasks->isEmpty())
                        <p class="px-4 py-4 text-sm text-gray-400">{{ $empty }}</p>
                    @else
                        @include('workflow.partials.work-task-rows', ['tasks' => $tasks])
                    @endif
                </div>
            @endforeach

            <div class="bg-white rounded-xl border border-gray-200/80 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900">Recently handed back</h3>
                    <p class="text-xs text-gray-400">Uploaded and sent to the Brand Manager — the job leaves your workspace once you hand it back.</p>
                </div>
                @forelse ($handedBack as $task)
                    <div class="px-4 py-2.5 border-t border-gray-100 first:border-t-0 flex items-center justify-between text-sm">
                        <span class="text-gray-800">{{ $task->document?->title }} <span class="text-xs text-gray-400 font-mono ml-1">{{ $task->document?->reference_no }}</span></span>
                        <span class="text-xs text-gray-500">{{ $task->typeLabel() }} · {{ $task->completer?->name }} · {{ $task->completed_at?->format('d M Y, H:i') }}</span>
                    </div>
                @empty
                    <p class="px-4 py-4 text-sm text-gray-400">Nothing handed back yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
