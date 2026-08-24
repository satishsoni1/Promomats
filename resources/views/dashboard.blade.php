@php
    // Same 6-bucket status scheme as Projects/Cycles/Admin dashboard (Project::STATUS_BUCKETS)
    // - reused rather than redefined, so a color means the same thing everywhere in the app.
    $bucketColors = [
        'draft' => 'bg-gray-300',
        'in_review' => 'bg-sky-500',
        'needs_attention' => 'bg-amber-500',
        'approved' => 'bg-brand-600',
        'expiring' => 'bg-red-500',
        'retired' => 'bg-gray-400',
    ];

    $maxMonth = max(1, $myDocumentsByMonth->max('count'));
    $chartW = 640; $chartH = 160; $padL = 28; $padR = 12; $padT = 12; $padB = 24;
    $plotW = $chartW - $padL - $padR; $plotH = $chartH - $padT - $padB;
    $n = max(1, $myDocumentsByMonth->count() - 1);
    $points = $myDocumentsByMonth->values()->map(function ($m, $i) use ($n, $plotW, $plotH, $padL, $padT, $maxMonth) {
        $x = $padL + ($n === 0 ? 0 : ($i / $n) * $plotW);
        $y = $padT + $plotH - ($maxMonth === 0 ? 0 : ($m['count'] / $maxMonth) * $plotH);
        return ['x' => round($x, 1), 'y' => round($y, 1), 'label' => $m['label'], 'count' => $m['count']];
    });
    $linePath = $points->map(fn ($p, $i) => ($i === 0 ? 'M' : 'L') . $p['x'] . ',' . $p['y'])->implode(' ');
    $areaPath = $linePath . ' L' . $points->last()['x'] . ',' . ($padT + $plotH) . ' L' . $points->first()['x'] . ',' . ($padT + $plotH) . ' Z';
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <!-- Stat tiles -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('documents.index', ['mine' => 1]) }}" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-5 hover:shadow-md transition">
                    <div class="text-sm text-gray-500">My Documents</div>
                    <div class="text-3xl font-bold text-gray-900 mt-1" style="font-variant-numeric: tabular-nums;">{{ $myDocumentsCount }}</div>
                </a>
                <a href="{{ route('approvals.inbox') }}" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-5 hover:shadow-md transition">
                    <div class="text-sm text-gray-500">Pending My Approval</div>
                    <div class="text-3xl font-bold text-red-600 mt-1" style="font-variant-numeric: tabular-nums;">{{ $pendingApprovalsCount }}</div>
                </a>
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-5">
                    <div class="text-sm text-gray-500">My Documents In Review</div>
                    <div class="text-3xl font-bold text-amber-600 mt-1" style="font-variant-numeric: tabular-nums;">{{ $inReviewCount }}</div>
                </div>
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-5">
                    <div class="text-sm text-gray-500">Aging / Expiring Soon</div>
                    <div class="text-3xl font-bold text-amber-600 mt-1" style="font-variant-numeric: tabular-nums;">{{ $agingCount }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- My Documents by Status -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-1">My Documents by Status</h3>
                    <p class="text-xs text-gray-500 mb-4">
                        Average time to approve: <span class="font-medium text-gray-700">{{ $myAvgApprovalDays !== null ? $myAvgApprovalDays . ' days' : '—' }}</span>
                    </p>
                    @if ($myDocumentsCount > 0)
                        <div class="flex h-3 rounded-full overflow-hidden bg-gray-100 mb-3">
                            @foreach ($myStatusBreakdown as $bucket => $count)
                                @if ($count > 0)
                                    <div class="{{ $bucketColors[$bucket] }}" style="width: {{ ($count / $myDocumentsCount) * 100 }}%" title="{{ \App\Models\Project::BUCKET_LABELS[$bucket] }}: {{ $count }}"></div>
                                @endif
                            @endforeach
                        </div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                            @foreach (\App\Models\Project::BUCKET_LABELS as $bucket => $label)
                                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full {{ $bucketColors[$bucket] }}"></span>{{ $label }} ({{ $myStatusBreakdown[$bucket] }})</span>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Upload your first document to see a breakdown here.</p>
                    @endif
                </div>

                <!-- My Documents by Month -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">My Documents Uploaded by Month</h3>
                    <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" class="w-full h-auto" role="img" aria-label="My documents uploaded per month, last 12 months">
                        <defs>
                            <linearGradient id="myAreaFade" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#127277" stop-opacity="0.18" />
                                <stop offset="100%" stop-color="#127277" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        @for ($g = 0; $g <= 3; $g++)
                            @php $gy = $padT + $plotH - ($g / 3) * $plotH; @endphp
                            <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $chartW - $padR }}" y2="{{ $gy }}" stroke="#e1e0d9" stroke-width="1" />
                        @endfor
                        <path d="{{ $areaPath }}" fill="url(#myAreaFade)" />
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

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Pending approvals -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">Waiting on Your Approval</h3>
                        <a href="{{ route('approvals.inbox') }}" class="text-sm text-brand-600 hover:underline">View all</a>
                    </div>
                    @forelse ($pendingApprovals as $row)
                        <a href="{{ route('documents.show', $row->assignee->instance->document_id) }}" class="block py-3 border-b last:border-b-0 border-gray-100 hover:bg-gray-50 -mx-2 px-2 rounded">
                            <div class="flex items-center justify-between">
                                <div class="font-medium text-gray-900">{{ $row->assignee->instance->document->title }}</div>
                                @if ($row->overdue)
                                    <span class="text-xs text-[#d03b3b] font-medium whitespace-nowrap">🚩 {{ $row->hours_waiting }}h waiting</span>
                                @endif
                            </div>
                            <div class="text-sm text-gray-500">Stage: {{ $row->assignee->stage->name }} &middot; assigned {{ $row->assignee->assigned_at?->diffForHumans() }}</div>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">Nothing pending your approval right now.</p>
                    @endforelse
                </div>

                <!-- My recent documents -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">My Recent Documents</h3>
                        <a href="{{ route('documents.create') }}" class="text-sm text-brand-600 hover:underline">+ Upload new</a>
                    </div>
                    @forelse ($recentDocuments as $document)
                        <a href="{{ route('documents.show', $document) }}" class="block py-3 border-b last:border-b-0 border-gray-100 hover:bg-gray-50 -mx-2 px-2 rounded">
                            <div class="flex items-center justify-between">
                                <div class="font-medium text-gray-900">{{ $document->title }}</div>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ str($document->status)->headline() }}</span>
                            </div>
                            <div class="text-sm text-gray-500">{{ $document->reference_no }}</div>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">You haven't uploaded any documents yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- My Projects -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">My Projects</h3>
                        <a href="{{ route('projects.index') }}" class="text-sm text-brand-600 hover:underline">All projects</a>
                    </div>
                    @forelse ($myProjects as $row)
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
                        <p class="text-sm text-gray-500">You're not leading or contributing to any project yet.</p>
                    @endforelse
                </div>

                <!-- My signing history -->
                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">My Signing History</h3>
                    <p class="text-xs text-gray-500 mb-4">Decisions you've electronically signed &mdash; 21 CFR Part 11.</p>
                    @forelse ($mySignatures as $action)
                        <a href="{{ route('documents.show', $action->document_id) }}" class="block py-2.5 border-b last:border-b-0 border-gray-100 hover:bg-gray-50 -mx-2 px-2 rounded text-sm">
                            <div class="flex items-center justify-between">
                                <span class="font-medium text-gray-900 truncate">{{ $action->document?->title }}</span>
                                <span class="text-gray-400 text-xs shrink-0 whitespace-nowrap">{{ $action->acted_at?->diffForHumans() }}</span>
                            </div>
                            <div class="text-gray-500 text-xs">{{ $action->decisionLabel() }} at <em>{{ $action->stage?->name }}</em></div>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">You haven't signed any approval decisions yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
