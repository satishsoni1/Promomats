@php
    $maxStatus = max(1, $materialsByStatus->max('count'));
    $maxMonth = max(1, $contentByMonth->max('count'));

    // Same 6-bucket status scheme as the Projects/Cycles pages (Project::STATUS_BUCKETS)
    // - reused here rather than redefined, so a color means the same thing everywhere
    // in the app that shows a status breakdown.
    $bucketColors = [
        'draft' => 'bg-gray-300',
        'in_review' => 'bg-sky-500',
        'needs_attention' => 'bg-amber-500',
        'approved' => 'bg-brand-600',
        'expiring' => 'bg-red-500',
        'retired' => 'bg-gray-400',
    ];

    // Line chart geometry
    $chartW = 640; $chartH = 180; $padL = 28; $padR = 12; $padT = 12; $padB = 24;
    $plotW = $chartW - $padL - $padR; $plotH = $chartH - $padT - $padB;
    $n = max(1, $contentByMonth->count() - 1);
    $points = $contentByMonth->values()->map(function ($m, $i) use ($n, $plotW, $plotH, $padL, $padT, $maxMonth) {
        $x = $padL + ($n === 0 ? 0 : ($i / $n) * $plotW);
        $y = $padT + $plotH - ($maxMonth === 0 ? 0 : ($m['count'] / $maxMonth) * $plotH);
        return ['x' => round($x, 1), 'y' => round($y, 1), 'label' => $m['label'], 'count' => $m['count']];
    });
    $linePath = $points->map(fn ($p, $i) => ($i === 0 ? 'M' : 'L') . $p['x'] . ',' . $p['y'])->implode(' ');
    $areaPath = $linePath . ' L' . $points->last()['x'] . ',' . ($padT + $plotH) . ' L' . $points->first()['x'] . ',' . ($padT + $plotH) . ' Z';
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <!-- Stat tiles -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-5">
                    <div class="text-sm text-gray-500">Approved Materials</div>
                    <div class="text-3xl font-bold text-gray-900 mt-1" style="font-variant-numeric: tabular-nums;">{{ $approvedMaterials }}</div>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-5">
                    <div class="text-sm text-gray-500">Average Time to Approve</div>
                    <div class="text-3xl font-bold text-gray-900 mt-1" style="font-variant-numeric: tabular-nums;">
                        {{ $avgApprovalDays ?? '—' }}<span class="text-base font-normal text-gray-500">{{ $avgApprovalDays !== null ? ' days' : '' }}</span>
                    </div>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-5">
                    <div class="text-sm text-gray-500">Overdue Tasks</div>
                    <div class="text-3xl font-bold {{ $overdueTasks->isEmpty() ? 'text-gray-900' : 'text-[#d03b3b]' }} mt-1" style="font-variant-numeric: tabular-nums;">{{ $overdueTasks->count() }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Materials by Status: horizontal bar chart, single sequential hue -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">Materials by Status</h3>
                    <div class="space-y-2.5">
                        @foreach ($materialsByStatus as $row)
                            <div class="flex items-center gap-3 text-xs">
                                <div class="w-40 shrink-0 text-gray-600 text-right">{{ $row['label'] }}</div>
                                <div class="flex-1 bg-gray-100 rounded-full h-4 relative overflow-hidden">
                                    <div class="h-4 bg-brand-600 rounded-full transition-all" style="width: {{ $row['count'] === 0 ? 0 : max(3, round($row['count'] / $maxStatus * 100)) }}%" title="{{ $row['label'] }}: {{ $row['count'] }}"></div>
                                </div>
                                <div class="w-6 shrink-0 text-gray-900 font-medium" style="font-variant-numeric: tabular-nums;">{{ $row['count'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Content Created by Month: line chart, single series -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">Content Created by Month</h3>
                    <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" class="w-full h-auto" role="img" aria-label="Documents created per month, last 12 months">
                        <defs>
                            <linearGradient id="areaFade" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#127277" stop-opacity="0.18" />
                                <stop offset="100%" stop-color="#127277" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <!-- gridlines -->
                        @for ($g = 0; $g <= 3; $g++)
                            @php $gy = $padT + $plotH - ($g / 3) * $plotH; @endphp
                            <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $chartW - $padR }}" y2="{{ $gy }}" stroke="#e1e0d9" stroke-width="1" />
                        @endfor
                        <path d="{{ $areaPath }}" fill="url(#areaFade)" />
                        <path d="{{ $linePath }}" fill="none" stroke="#127277" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        @foreach ($points as $i => $p)
                            <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="3" fill="#127277">
                                <title>{{ $p['label'] }}: {{ $p['count'] }} document(s)</title>
                            </circle>
                            @if ($i % 2 === 0 || $points->count() <= 6)
                                <text x="{{ $p['x'] }}" y="{{ $chartH - 6 }}" font-size="9" fill="#898781" text-anchor="middle">{{ $p['label'] }}</text>
                            @endif
                        @endforeach
                    </svg>
                </div>
            </div>

            <!-- Overdue Tasks -->
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 mb-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Overdue Tasks</h3>
                @forelse ($overdueTasks as $row)
                    <div class="flex items-center justify-between py-2.5 border-b last:border-b-0 border-gray-100 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full shrink-0" style="background:#d03b3b" aria-hidden="true"></span>
                            <div>
                                <a href="{{ route('documents.show', $row->assignee->instance->document_id) }}" class="font-medium text-gray-900 hover:text-brand-600">{{ $row->assignee->instance->document->title }}</a>
                                <span class="text-gray-500"> &middot; {{ $row->assignee->stage->name }} &middot; assigned to {{ $row->assignee->user?->name }}</span>
                            </div>
                        </div>
                        <span class="text-xs text-[#d03b3b] font-medium whitespace-nowrap">{{ $row->hours_waiting }}h waiting (SLA {{ $row->sla_hours }}h)</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Nothing overdue right now.</p>
                @endforelse
            </div>

            <!-- Multi-level analytics: legend shared by the three breakdown tables below -->
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 mb-3">
                @foreach (\App\Models\Project::BUCKET_LABELS as $bucket => $label)
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full {{ $bucketColors[$bucket] }}"></span>{{ $label }}</span>
                @endforeach
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- By Project -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-semibold text-gray-900">By Project</h3>
                        <a href="{{ route('projects.index') }}" class="text-xs text-brand-600 hover:underline">All projects &rarr;</a>
                    </div>
                    @forelse ($byProject as $row)
                        <a href="{{ route('projects.show', $row['project']) }}" class="block py-2.5 border-b last:border-b-0 border-gray-100 hover:bg-gray-50 -mx-2 px-2 rounded">
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="font-medium text-gray-900 truncate">{{ $row['project']->name }}</span>
                                <span class="text-gray-500 shrink-0" style="font-variant-numeric: tabular-nums;">{{ $row['total'] }}</span>
                            </div>
                            @if ($row['total'] > 0)
                                <div class="flex h-1.5 rounded-full overflow-hidden bg-gray-100">
                                    @foreach ($row['breakdown'] as $bucket => $count)
                                        @if ($count > 0)
                                            <div class="{{ $bucketColors[$bucket] }}" style="width: {{ ($count / $row['total']) * 100 }}%" title="{{ \App\Models\Project::BUCKET_LABELS[$bucket] }}: {{ $count }}"></div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No active projects yet.</p>
                    @endforelse
                </div>

                <!-- By Cycle (cross-project) -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">By Cycle</h3>
                    @forelse ($byCycle as $row)
                        <a href="{{ route('projects.cycles.show', [$row['cycle']->project, $row['cycle']]) }}" class="block py-2.5 border-b last:border-b-0 border-gray-100 hover:bg-gray-50 -mx-2 px-2 rounded">
                            <div class="flex items-center justify-between text-sm mb-0.5">
                                <span class="font-medium text-gray-900 truncate">{{ $row['cycle']->name }}</span>
                                <span class="text-gray-500 shrink-0" style="font-variant-numeric: tabular-nums;">{{ $row['total'] }}</span>
                            </div>
                            <p class="text-xs text-gray-400 mb-1">{{ $row['cycle']->project->name }}</p>
                            @if ($row['total'] > 0)
                                <div class="flex h-1.5 rounded-full overflow-hidden bg-gray-100">
                                    @foreach ($row['breakdown'] as $bucket => $count)
                                        @if ($count > 0)
                                            <div class="{{ $bucketColors[$bucket] }}" style="width: {{ ($count / $row['total']) * 100 }}%" title="{{ \App\Models\Project::BUCKET_LABELS[$bucket] }}: {{ $count }}"></div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No active cycles yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- By User: workload / bottleneck view -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">By User</h3>
                    <p class="text-xs text-gray-500 mb-4">Who owns what, and who currently has approvals waiting on them.</p>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-400 text-xs uppercase tracking-wide">
                                <th class="pb-2 font-medium">User</th>
                                <th class="pb-2 font-medium text-right">Owns</th>
                                <th class="pb-2 font-medium text-right">Pending on them</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse ($byUser as $row)
                                <tr>
                                    <td class="py-2 text-gray-900">{{ $row['user']->name }}</td>
                                    <td class="py-2 text-right text-gray-600" style="font-variant-numeric: tabular-nums;">{{ $row['owned_total'] }}</td>
                                    <td class="py-2 text-right" style="font-variant-numeric: tabular-nums;">
                                        @if ($row['pending_approvals'] > 0)
                                            <span class="{{ $row['overdue_approvals'] > 0 ? 'text-[#d03b3b] font-semibold' : 'text-gray-700' }}">
                                                {{ $row['pending_approvals'] }}
                                                @if ($row['overdue_approvals'] > 0)
                                                    <span title="{{ $row['overdue_approvals'] }} overdue">🚩</span>
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-gray-300">0</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-4 text-gray-500">No user activity yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Needs Attention: file-level, revision loops and rejections -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">Needs Attention</h3>
                    <p class="text-xs text-gray-500 mb-4">Documents in a revision loop or rejected, oldest first.</p>
                    @forelse ($needsAttention as $document)
                        <a href="{{ route('documents.show', $document) }}" class="flex items-center justify-between py-2 border-b last:border-b-0 border-gray-50 hover:bg-gray-50 -mx-2 px-2 rounded text-sm">
                            <div class="min-w-0">
                                <span class="text-gray-900 truncate">{{ $document->title }}</span>
                                <span class="text-gray-400"> &middot; {{ $document->owner?->name }}</span>
                            </div>
                            <span class="text-xs text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full shrink-0 whitespace-nowrap">{{ $document->statusLabel() }} &middot; {{ $document->status_changed_at?->diffForHumans() }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">Nothing needs attention right now.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
