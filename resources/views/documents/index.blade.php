@php
    $fileIcons = ['pdf' => '📄', 'doc' => '📝', 'docx' => '📝', 'ppt' => '📊', 'pptx' => '📊', 'xls' => '📈', 'xlsx' => '📈', 'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'mp4' => '🎞️', 'zip' => '🗜️'];
    $iconFor = function ($doc) use ($fileIcons) {
        $ext = strtolower(pathinfo($doc->currentVersion?->original_filename ?? '', PATHINFO_EXTENSION));
        return $fileIcons[$ext] ?? '📁';
    };

    $total = $statusCounts->sum();
    $inReviewCount = $statusCounts['in_review'] ?? 0;
    $approvedCount = ($statusCounts['approved'] ?? 0) + ($statusCounts['approved_for_production'] ?? 0) + ($statusCounts['approved_for_distribution'] ?? 0);
    $needsActionCount = ($statusCounts['rejected'] ?? 0) + ($statusCounts['approved_with_changes_pending'] ?? 0) + ($statusCounts['expired'] ?? 0);
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Documents') }}</h2>
                <p class="text-xs text-gray-500 mt-0.5">{{ number_format($total) }} document{{ $total === 1 ? '' : 's' }} in the system</p>
            </div>
            <a href="{{ route('documents.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest shadow-sm hover:bg-brand-700 hover:shadow transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Upload Document
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <!-- KPI summary -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('documents.index') }}" class="bg-white rounded-xl border border-gray-200/80 p-4 hover:border-brand-300 hover:shadow-sm transition">
                    <p class="text-xs font-medium text-gray-500">Total documents</p>
                    <p class="text-2xl font-semibold text-gray-900 mt-1">{{ number_format($total) }}</p>
                </a>
                <a href="{{ route('documents.index', ['status' => 'in_review']) }}" class="bg-white rounded-xl border border-gray-200/80 p-4 hover:border-amber-300 hover:shadow-sm transition">
                    <p class="text-xs font-medium text-gray-500">In review</p>
                    <p class="text-2xl font-semibold text-amber-600 mt-1">{{ number_format($inReviewCount) }}</p>
                </a>
                <a href="{{ route('documents.index', ['status' => 'approved']) }}" class="bg-white rounded-xl border border-gray-200/80 p-4 hover:border-emerald-300 hover:shadow-sm transition">
                    <p class="text-xs font-medium text-gray-500">Approved</p>
                    <p class="text-2xl font-semibold text-emerald-600 mt-1">{{ number_format($approvedCount) }}</p>
                </a>
                <a href="{{ route('documents.index', ['status' => 'rejected']) }}" class="bg-white rounded-xl border border-gray-200/80 p-4 hover:border-red-300 hover:shadow-sm transition">
                    <p class="text-xs font-medium text-gray-500">Needs attention</p>
                    <p class="text-2xl font-semibold text-red-600 mt-1">{{ number_format($needsActionCount) }}</p>
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[240px_1fr] gap-6 items-start">
                <!-- Filter sidebar -->
                <aside class="space-y-5 lg:sticky lg:top-6 bg-white rounded-xl border border-gray-200/80 p-4">
                    <div>
                        <h4 class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Status</h4>
                        <ul class="space-y-0.5 text-sm">
                            <li>
                                <a href="{{ route('documents.index') }}" class="flex items-center justify-between px-2.5 py-1.5 rounded-lg {{ ! request('status') ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                                    <span>All</span>
                                    <span class="text-xs {{ ! request('status') ? 'text-brand-600' : 'text-gray-400' }}">{{ $statusCounts->sum() }}</span>
                                </a>
                            </li>
                            @foreach (\App\Models\Document::STATUS_LABELS as $status => $label)
                                @if ($statusCounts[$status] ?? 0)
                                    <li>
                                        <a href="{{ route('documents.index', array_merge(request()->except('page'), ['status' => $status])) }}" class="flex items-center justify-between px-2.5 py-1.5 rounded-lg {{ request('status') === $status ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                                            <span class="truncate">{{ $label }}</span>
                                            <span class="text-xs {{ request('status') === $status ? 'text-brand-600' : 'text-gray-400' }}">{{ $statusCounts[$status] }}</span>
                                        </a>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>

                    @if ($categoryCounts->isNotEmpty())
                        <div class="pt-4 border-t border-gray-100">
                            <h4 class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Category</h4>
                            <ul class="space-y-0.5 text-sm">
                                @foreach ($categoryCounts as $category => $count)
                                    <li>
                                        <a href="{{ route('documents.index', array_merge(request()->except('page'), ['category' => $category])) }}" class="flex items-center justify-between px-2.5 py-1.5 rounded-lg {{ request('category') === $category ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-600 hover:bg-gray-50' }}">
                                            <span class="truncate">{{ $category }}</span>
                                            <span class="text-xs {{ request('category') === $category ? 'text-brand-600' : 'text-gray-400' }}">{{ $count }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </aside>

                <div class="space-y-4 min-w-0">
                    <div class="bg-white rounded-xl border border-gray-200/80 p-4 flex flex-wrap gap-3 items-end justify-between">
                        <form method="GET" class="flex flex-wrap gap-3 items-end flex-1 min-w-0">
                            <input type="hidden" name="status" value="{{ request('status') }}">
                            <input type="hidden" name="category" value="{{ request('category') }}">
                            <div class="relative">
                                <x-input-label for="search" value="Search" class="!text-xs !text-gray-500 !font-medium mb-1" />
                                <svg class="w-4 h-4 text-gray-400 absolute left-2.5 top-[calc(50%+3px)] -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" /></svg>
                                <x-text-input id="search" name="search" value="{{ request('search') }}" class="!pl-8 w-56 text-sm" placeholder="Title or reference no." />
                            </div>
                            <label class="flex items-center gap-2 text-sm text-gray-600 pb-2 cursor-pointer select-none">
                                <input type="checkbox" name="mine" value="1" @checked(request('mine')) class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                My documents only
                            </label>
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-brand-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-700 transition">Filter</button>
                            @if (request()->anyFilled(['status', 'category', 'search', 'mine']))
                                <a href="{{ route('documents.index') }}" class="text-sm text-gray-400 hover:text-gray-700 pb-2">Reset</a>
                            @endif
                        </form>

                        <div class="flex items-center gap-1 bg-gray-100 rounded-lg p-1 shrink-0">
                            <a href="{{ route('documents.index', array_merge(request()->except('page'), ['view' => 'table'])) }}" title="List view" class="flex items-center gap-1.5 px-2.5 py-1.5 text-xs rounded-md transition {{ $view === 'table' ? 'bg-white shadow-sm font-medium text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
                                List
                            </a>
                            <a href="{{ route('documents.index', array_merge(request()->except('page'), ['view' => 'grid'])) }}" title="Grid view" class="flex items-center gap-1.5 px-2.5 py-1.5 text-xs rounded-md transition {{ $view === 'grid' ? 'bg-white shadow-sm font-medium text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5h6v6H4V5Zm10 0h6v6h-6V5ZM4 15h6v6H4v-6Zm10 0h6v6h-6v-6Z" /></svg>
                                Grid
                            </a>
                        </div>
                    </div>

                    @if ($view === 'grid')
                        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-4">
                            @forelse ($documents as $document)
                                <a href="{{ route('documents.show', $document) }}" class="group bg-white rounded-xl border border-gray-200/80 overflow-hidden hover:border-brand-300 hover:shadow-md hover:-translate-y-0.5 transition">
                                    <div class="h-24 bg-gradient-to-br from-gray-50 to-gray-100 flex items-center justify-center text-4xl border-b border-gray-100">
                                        {{ $iconFor($document) }}
                                    </div>
                                    <div class="p-3.5 space-y-2">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 truncate group-hover:text-brand-600">{{ $document->title }}</div>
                                            <div class="text-xs text-gray-400 font-mono truncate">{{ $document->reference_no }}</div>
                                        </div>
                                        <div class="flex items-center justify-between gap-1">
                                            <x-status-badge :status="$document->status">{{ $document->statusLabel() }}</x-status-badge>
                                            @if ($document->is_expired)
                                                <span class="text-[10px] text-red-600 font-medium">Expired</span>
                                            @elseif ($document->is_aging_flagged)
                                                <span class="text-[10px] text-amber-600 font-medium">Aging</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-1.5 pt-1 border-t border-gray-50">
                                            <x-avatar :name="$document->owner?->name" size="xs" />
                                            <span class="text-xs text-gray-500 truncate">{{ $document->owner?->name }}</span>
                                        </div>
                                    </div>
                                </a>
                            @empty
                                <div class="col-span-full">
                                    @include('documents.partials.empty-state')
                                </div>
                            @endforelse
                        </div>
                    @else
                        <div class="bg-white rounded-xl border border-gray-200/80 overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-100 text-sm">
                                    <thead class="bg-gray-50/80">
                                        <tr>
                                            <th class="px-4 py-3 text-left font-medium text-gray-500 text-xs uppercase tracking-wide">Reference</th>
                                            <th class="px-4 py-3 text-left font-medium text-gray-500 text-xs uppercase tracking-wide">Title</th>
                                            <th class="px-4 py-3 text-left font-medium text-gray-500 text-xs uppercase tracking-wide">Owner</th>
                                            <th class="px-4 py-3 text-left font-medium text-gray-500 text-xs uppercase tracking-wide">Workflow</th>
                                            <th class="px-4 py-3 text-left font-medium text-gray-500 text-xs uppercase tracking-wide">Status</th>
                                            <th class="px-4 py-3 text-left font-medium text-gray-500 text-xs uppercase tracking-wide">Updated</th>
                                            <th class="px-4 py-3"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse ($documents as $document)
                                            <tr class="group hover:bg-gray-50/70 transition">
                                                <td class="px-4 py-3 font-mono text-xs text-gray-500">
                                                    <a href="{{ route('documents.show', $document) }}" class="hover:text-brand-600">{{ $document->reference_no }}</a>
                                                </td>
                                                <td class="px-4 py-3 max-w-xs">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-base shrink-0">{{ $iconFor($document) }}</span>
                                                        <div class="min-w-0">
                                                            <a href="{{ route('documents.show', $document) }}" class="font-medium text-gray-900 hover:text-brand-600 truncate block">{{ $document->title }}</a>
                                                            <div class="flex items-center gap-1 mt-0.5">
                                                                @if ($document->is_expired)
                                                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-medium">Expired</span>
                                                                @elseif ($document->is_aging_flagged)
                                                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 font-medium">Aging</span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3 text-gray-600">
                                                    <div class="flex items-center gap-2">
                                                        <x-avatar :name="$document->owner?->name" size="xs" />
                                                        <span class="truncate">{{ $document->owner?->name }}</span>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3 text-gray-500">{{ $document->workflowTemplate?->name ?? '—' }}</td>
                                                <td class="px-4 py-3">
                                                    <x-status-badge :status="$document->status">{{ $document->statusLabel() }}</x-status-badge>
                                                </td>
                                                <td class="px-4 py-3 text-gray-400 text-xs whitespace-nowrap">{{ $document->updated_at->diffForHumans() }}</td>
                                                <td class="px-4 py-3 text-right">
                                                    <form method="POST" action="{{ route('basket.add', $document) }}">
                                                        @csrf
                                                        <button class="text-xs text-gray-400 hover:text-brand-600 opacity-0 group-hover:opacity-100 transition" title="Add to download basket">+ Basket</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="7">
                                                    @include('documents.partials.empty-state')
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <div class="pt-1">
                        {{ $documents->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
