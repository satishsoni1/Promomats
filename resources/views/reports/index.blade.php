@php
    $tabs = [
        'approvals' => 'Approval Report',
        'sla' => 'SLA Report',
        'revisions' => 'Revision Report',
        'workflows' => 'Workflow Report',
    ];
    $descriptions = [
        'approvals' => 'Every signed decision — who reviewed what, at which stage, and how long it took.',
        'sla' => "Each reviewer's workload and turnaround against their stages' SLA.",
        'revisions' => 'Every "Approved with Changes" decision — what was sent back, and why.',
        'workflows' => 'Volume and average duration per workflow template.',
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Reports</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">

            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div class="flex gap-2">
                    @foreach ($tabs as $key => $label)
                        <a href="{{ route('reports.index', ['report' => $key]) }}"
                           class="text-xs px-3 py-1.5 rounded-full border {{ $report === $key ? 'bg-brand-600 border-brand-600 text-white' : 'border-gray-200 text-gray-600 hover:border-brand-300' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
                <a href="{{ route('reports.export', ['report' => $report]) }}" class="text-xs px-3 py-1.5 border border-gray-300 rounded-md text-gray-600 hover:border-brand-300 hover:text-brand-700">
                    ⬇ Export CSV
                </a>
            </div>

            <p class="text-sm text-gray-500 mb-4">{{ $descriptions[$report] }}</p>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-x-auto">
                @if ($rows->isEmpty())
                    <p class="p-6 text-sm text-gray-500 text-center">No data yet for this report.</p>
                @else
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach (array_keys($rows->first()) as $column)
                                    <th class="px-4 py-3 text-left font-medium text-gray-500 whitespace-nowrap">{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($rows as $row)
                                <tr>
                                    @foreach ($row as $value)
                                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $value }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
