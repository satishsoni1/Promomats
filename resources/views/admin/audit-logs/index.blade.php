<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-1">Audit Logs</h3>
                <p class="text-sm text-gray-500">
                    Every action recorded against a document — created, viewed, downloaded, submitted, assigned,
                    approved, rejected, resubmitted, and archived. Signed A / AwC / NA decisions specifically also
                    appear on each document's <span class="italic">History Report</span> with their electronic
                    signature. This trail is append-only: nothing here can be edited or deleted from the UI.
                </p>
            </div>

            <form method="GET" class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-4 mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Document</label>
                    <select name="document_id" class="text-sm rounded-md border-gray-300">
                        <option value="">All documents</option>
                        @foreach ($documents as $doc)
                            <option value="{{ $doc->id }}" @selected(request('document_id') == $doc->id)>{{ $doc->reference_no }} — {{ Str::limit($doc->title, 40) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Action</label>
                    <select name="action" class="text-sm rounded-md border-gray-300">
                        <option value="">All actions</option>
                        @foreach ($actions as $key => $label)
                            <option value="{{ $key }}" @selected(request('action') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="text-sm rounded-md border-gray-300">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="text-sm rounded-md border-gray-300">
                </div>
                <button class="text-xs px-3 py-2 bg-brand-600 text-white rounded-md hover:bg-brand-700">Filter</button>
                @if (request()->anyFilled(['document_id', 'action', 'user_id', 'date_from', 'date_to']))
                    <a href="{{ route('admin.audit-logs.index') }}" class="text-xs px-3 py-2 text-gray-500 hover:underline">Clear</a>
                @endif
            </form>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <tr>
                            <th class="text-left px-4 py-2">When</th>
                            <th class="text-left px-4 py-2">Action</th>
                            <th class="text-left px-4 py-2">Document</th>
                            <th class="text-left px-4 py-2">Actor</th>
                            <th class="text-left px-4 py-2">Status change</th>
                            <th class="text-left px-4 py-2">Detail</th>
                            <th class="text-left px-4 py-2">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($logs as $log)
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-gray-500">{{ $log->created_at->format('d M Y H:i') }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-gray-100 text-gray-700">{{ $log->actionLabel() }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    @if ($log->document)
                                        <a href="{{ route('documents.show', $log->document) }}" class="text-brand-700 hover:underline">{{ $log->document->reference_no }}</a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ $log->user?->name ?? 'System' }}</td>
                                <td class="px-4 py-2 whitespace-nowrap text-gray-500">
                                    @if ($log->old_status || $log->new_status)
                                        {{ $log->old_status ?? '—' }} → {{ $log->new_status ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-gray-500 max-w-xs truncate" title="{{ $log->description }}">{{ $log->description }}</td>
                                <td class="px-4 py-2 whitespace-nowrap text-gray-400">{{ $log->ip_address }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-6 text-center text-sm text-gray-500">No audit log entries match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $logs->links() }}</div>
        </div>
    </div>
</x-app-layout>
