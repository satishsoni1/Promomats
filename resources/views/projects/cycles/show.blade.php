@php
    $bucketColors = [
        'draft' => 'bg-gray-300',
        'in_review' => 'bg-sky-500',
        'needs_attention' => 'bg-amber-500',
        'approved' => 'bg-brand-600',
        'expiring' => 'bg-red-500',
        'retired' => 'bg-gray-400',
    ];
    $total = array_sum($breakdown);
    $canManage = auth()->id() === $cycle->created_by || auth()->id() === $project->created_by || auth()->id() === $project->lead_id || auth()->user()->can('access-admin');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs text-gray-400">
                    <a href="{{ route('projects.show', $project) }}" class="hover:text-brand-600 hover:underline">{{ $project->name }}</a> / Cycle
                </p>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $cycle->name }}</h2>
            </div>
            <span @class([
                'text-xs px-3 py-1 rounded-full font-medium',
                'bg-green-100 text-green-700' => $cycle->status === 'active',
                'bg-gray-100 text-gray-600' => $cycle->status === 'completed',
                'bg-gray-50 text-gray-400' => $cycle->status === 'archived',
            ])>{{ ucfirst($cycle->status) }}</span>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg p-4">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-lg p-4">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">

                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Status Overview</h3>
                        @if ($total > 0)
                            <div class="flex h-3 rounded-full overflow-hidden bg-gray-100 mb-3">
                                @foreach ($breakdown as $bucket => $count)
                                    @if ($count > 0)
                                        <div class="{{ $bucketColors[$bucket] }}" style="width: {{ ($count / $total) * 100 }}%" title="{{ \App\Models\Project::BUCKET_LABELS[$bucket] }}: {{ $count }}"></div>
                                    @endif
                                @endforeach
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
                                @foreach ($breakdown as $bucket => $count)
                                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full {{ $bucketColors[$bucket] }}"></span>{{ $count }} {{ \App\Models\Project::BUCKET_LABELS[$bucket] }}</span>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-gray-500">No documents assigned to this cycle yet.</p>
                        @endif
                    </div>

                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-lg font-semibold text-gray-900">Documents ({{ $documents->total() }})</h3>
                            @if ($availableDocuments->isNotEmpty())
                                <button type="button" onclick="document.getElementById('add-document-form').classList.toggle('hidden')" class="text-sm text-brand-600 hover:underline">+ Add document</button>
                            @endif
                        </div>

                        @if ($availableDocuments->isNotEmpty())
                            <form id="add-document-form" method="POST" action="{{ route('projects.cycles.documents.store', [$project, $cycle]) }}" class="hidden mb-4 p-4 bg-gray-50 rounded-md flex items-center gap-2">
                                @csrf
                                <select name="document_id" required class="text-sm border-gray-300 rounded-md flex-1">
                                    <option value="">Select a document from this project…</option>
                                    @foreach ($availableDocuments as $doc)
                                        <option value="{{ $doc->id }}">{{ $doc->title }}{{ $doc->cycle_id ? ' (currently in another cycle)' : '' }}</option>
                                    @endforeach
                                </select>
                                <x-primary-button type="submit">Add</x-primary-button>
                            </form>
                        @endif

                        <table class="min-w-full text-sm divide-y divide-gray-100">
                            <thead>
                                <tr class="text-left text-gray-500">
                                    <th class="py-2">Title</th>
                                    <th class="py-2">Status</th>
                                    <th class="py-2">Owner</th>
                                    <th class="py-2">Updated</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($documents as $document)
                                    <tr>
                                        <td class="py-2">
                                            <a href="{{ route('documents.show', $document) }}" class="text-brand-600 hover:underline">{{ $document->title }}</a>
                                            <div class="text-xs text-gray-400 font-mono">{{ $document->reference_no }}</div>
                                        </td>
                                        <td class="py-2"><span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ $document->statusLabel() }}</span></td>
                                        <td class="py-2 text-gray-600">{{ $document->owner?->name }}</td>
                                        <td class="py-2 text-gray-500">{{ $document->updated_at->diffForHumans() }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="py-4 text-gray-500">No documents in this cycle yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        <div class="mt-4">{{ $documents->links() }}</div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-lg font-semibold text-gray-900">Details</h3>
                            @if ($canManage)
                                <button type="button" onclick="document.getElementById('edit-cycle-form').classList.toggle('hidden')" class="text-xs text-brand-600 hover:underline">Edit</button>
                            @endif
                        </div>
                        <dl class="text-sm space-y-3">
                            <div>
                                <dt class="text-gray-500">Created by</dt>
                                <dd class="text-gray-900">{{ $cycle->creator?->name }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Start / End</dt>
                                <dd class="text-gray-900">{{ $cycle->start_date?->toFormattedDateString() ?? '—' }} &rarr; {{ $cycle->end_date?->toFormattedDateString() ?? '—' }}</dd>
                            </div>
                            @if ($cycle->description)
                                <div>
                                    <dt class="text-gray-500">Description</dt>
                                    <dd class="text-gray-900 whitespace-pre-line">{{ $cycle->description }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if ($canManage)
                            <form id="edit-cycle-form" method="POST" action="{{ route('projects.cycles.update', [$project, $cycle]) }}" class="hidden mt-4 pt-4 border-t border-gray-100 space-y-3">
                                @csrf
                                @method('PUT')
                                <input type="text" name="name" value="{{ $cycle->name }}" required class="block w-full text-sm border-gray-300 rounded-md">
                                <textarea name="description" rows="2" class="block w-full text-sm border-gray-300 rounded-md">{{ $cycle->description }}</textarea>
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="date" name="start_date" value="{{ $cycle->start_date?->toDateString() }}" class="text-sm border-gray-300 rounded-md">
                                    <input type="date" name="end_date" value="{{ $cycle->end_date?->toDateString() }}" class="text-sm border-gray-300 rounded-md">
                                </div>
                                <select name="status" class="block w-full text-sm border-gray-300 rounded-md">
                                    <option value="active" @selected($cycle->status === 'active')>Active</option>
                                    <option value="completed" @selected($cycle->status === 'completed')>Completed</option>
                                    <option value="archived" @selected($cycle->status === 'archived')>Archived</option>
                                </select>
                                <x-primary-button type="submit">Save Changes</x-primary-button>
                            </form>

                            @if ($cycle->isEmpty())
                                <form method="POST" action="{{ route('projects.cycles.destroy', [$project, $cycle]) }}" onsubmit="return confirm('Delete this empty cycle?');" class="mt-3">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-gray-400 hover:text-red-600">Delete this cycle</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
