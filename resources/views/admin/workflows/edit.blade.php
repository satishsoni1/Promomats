@php
    $decisions = [
        'approved' => 'A — Approved',
        'approved_with_changes' => 'AwC — Approved with Changes',
        'not_approved' => 'NA — Not Approved',
    ];
    $outcomes = [
        'next_stage' => 'Move to next stage',
        'return_to_stage' => 'Return for revision (loop)',
        'terminate_rejected' => 'Terminate — Rejected',
        'complete_approved' => 'Complete — Approved for Distribution',
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }} — {{ $workflow->name }} <span class="text-sm font-normal text-gray-400">v{{ $workflow->version }}</span></h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <p class="text-sm text-gray-500 mb-2">{{ $workflow->description }}</p>

            @if ($workflow->isLocked())
                <div class="rounded-md p-3 mb-6 text-xs border bg-amber-50 border-amber-200 text-amber-800 flex items-center justify-between gap-4">
                    <span>🔒 Locked — at least one document is already using this version, so its stages and transitions can't be edited in place.</span>
                    <form method="POST" action="{{ route('admin.workflows.new-version', $workflow) }}" onsubmit="return confirm('Create a new, freely-editable version? Documents already on v{{ $workflow->version }} are unaffected.');">
                        @csrf
                        <button class="whitespace-nowrap px-3 py-1.5 bg-amber-600 text-white rounded-md hover:bg-amber-700">Create New Version</button>
                    </form>
                </div>
            @else
                <p class="text-xs text-gray-400 mb-6">Editable — locks automatically the first time a document starts using this version.</p>
            @endif

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-1">Settings</h3>
                <p class="text-xs text-gray-400 mb-4">Editable even when the workflow is locked — these don't change any in-flight document's path.</p>
                <form method="POST" action="{{ route('admin.workflows.settings.update', $workflow) }}">
                    @csrf
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="owner_can_customize_workflow" value="1" @checked($workflow->owner_can_customize_workflow) class="mt-0.5 rounded border-gray-300 text-brand-600">
                        <span>
                            <span class="font-medium text-gray-800">Let the document owner pick approvers at upload</span>
                            <span class="block text-xs text-gray-500 mt-0.5">On the upload form the owner sees each stage and, where a stage has several candidate people, can send it to one named person. They can only narrow the candidates this template already defines — never add anyone new.</span>
                        </span>
                    </label>
                    <button class="mt-3 text-xs px-3 py-1.5 bg-brand-600 text-white rounded-md hover:bg-brand-700">Save settings</button>
                </form>
            </div>

            <div class="space-y-6 {{ $workflow->isLocked() ? 'opacity-60 pointer-events-none select-none' : '' }}">
                @foreach ($workflow->stages as $stage)
                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-lg font-semibold text-gray-900">
                                {{ $stage->sequence_no }}. {{ $stage->name }}
                                <span class="text-xs font-normal text-gray-500 font-mono">({{ $stage->code }})</span>
                            </h3>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">
                                @if ($stage->approval_mode === 'all_required')
                                    All approvers required
                                @elseif ($stage->approval_mode === 'majority')
                                    Majority{{ $stage->quorum_count ? " ({$stage->quorum_count} of " . $stage->approvers->count() . ')' : '' }}
                                @else
                                    Any one approver
                                @endif
                            </span>
                        </div>

                        @if ($stage->condition_json)
                            <div class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1 mb-3 inline-block">
                                ⚡ Conditional: runs only if <span class="font-mono">{{ $stage->condition_json['field'] ?? '' }} {{ $stage->condition_json['operator'] ?? '' }} {{ is_array($stage->condition_json['value'] ?? null) ? implode('|', $stage->condition_json['value']) : ($stage->condition_json['value'] ?? '') }}</span>
                            </div>
                        @endif

                        <div class="text-sm text-gray-600 mb-4">
                            Approvers:
                            @forelse ($stage->approvers as $approver)
                                <span class="inline-block px-2 py-0.5 bg-gray-50 border border-gray-200 rounded text-xs mr-1">{{ $approver->role?->name ?? $approver->user?->name }}</span>
                            @empty
                                <span class="text-red-600 text-xs">none configured</span>
                            @endforelse
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            @foreach ($decisions as $decisionKey => $decisionLabel)
                                @php $transition = $stage->transitions->firstWhere('decision', $decisionKey); @endphp
                                <form method="POST" action="{{ route('admin.workflows.stages.transitions.set', $stage) }}" class="border border-gray-200 rounded-md p-3 space-y-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="{{ $decisionKey }}">
                                    <div class="text-xs font-semibold text-gray-700">{{ $decisionLabel }}</div>
                                    <select name="outcome_type" class="w-full text-xs border-gray-300 rounded-md" onchange="this.form.querySelectorAll('.stage-target').forEach(el => el.classList.toggle('hidden', !['return_to_stage','next_stage'].includes(this.value)))">
                                        @foreach ($outcomes as $outcomeKey => $outcomeLabel)
                                            <option value="{{ $outcomeKey }}" @selected($transition?->outcome_type === $outcomeKey)>{{ $outcomeLabel }}</option>
                                        @endforeach
                                    </select>
                                    <div class="stage-target {{ in_array($transition?->outcome_type, ['return_to_stage','next_stage']) ? '' : 'hidden' }}">
                                        <label class="text-xs text-gray-500">Target stage</label>
                                        <select name="target_stage_id" class="w-full text-xs border-gray-300 rounded-md">
                                            <option value="">—</option>
                                            @foreach ($workflow->stages as $s)
                                                <option value="{{ $s->id }}" @selected($transition?->target_stage_id === $s->id)>{{ $s->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="stage-target {{ in_array($transition?->outcome_type, ['return_to_stage','next_stage']) ? '' : 'hidden' }}">
                                        <label class="text-xs text-gray-500">Resume-at stage (after revision clears)</label>
                                        <select name="resume_at_stage_id" class="w-full text-xs border-gray-300 rounded-md">
                                            <option value="">—</option>
                                            @foreach ($workflow->stages as $s)
                                                <option value="{{ $s->id }}" @selected($transition?->resume_at_stage_id === $s->id)>{{ $s->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <button class="text-xs text-brand-600 hover:underline">Save rule</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Add Stage</h3>
                    <form method="POST" action="{{ route('admin.workflows.stages.add', $workflow) }}" class="space-y-4">
                        @csrf
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="stage_name" value="Stage Name" />
                                <x-text-input id="stage_name" name="name" class="mt-1 block w-full" required placeholder="e.g. Legal Level 1" />
                            </div>
                            <div>
                                <x-input-label for="stage_code" value="Code" />
                                <x-text-input id="stage_code" name="code" class="mt-1 block w-full" required placeholder="e.g. LEGAL_L1" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div x-data="{ mode: 'any_one' }">
                                <x-input-label for="approval_mode" value="Approval Mode" />
                                <select id="approval_mode" name="approval_mode" x-model="mode" class="mt-1 block w-full border-gray-300 rounded-md">
                                    <option value="any_one">Any one approver</option>
                                    <option value="all_required">All approvers required</option>
                                    <option value="majority">Majority / quorum</option>
                                </select>
                                <div class="mt-2" x-show="mode === 'majority'" x-cloak>
                                    <x-input-label for="quorum_count" value="Exact quorum (optional)" />
                                    <x-text-input type="number" id="quorum_count" name="quorum_count" class="mt-1 block w-full" placeholder="Leave blank for a simple majority" />
                                </div>
                            </div>
                            <div>
                                <x-input-label for="sla_hours" value="SLA (hours, optional)" />
                                <x-text-input type="number" id="sla_hours" name="sla_hours" class="mt-1 block w-full" />
                            </div>
                        </div>
        <div class="flex items-center gap-6 text-sm">
                            <label class="flex items-center gap-2"><input type="checkbox" name="is_revision_stage" value="1" class="rounded border-gray-300 text-brand-600"> Revision stage (content returns here)</label>
                            <label class="flex items-center gap-2"><input type="checkbox" name="is_final_distribution_stage" value="1" class="rounded border-gray-300 text-brand-600"> Final distribution stage</label>
                        </div>

                        <div class="border border-gray-200 rounded-md p-3">
                            <p class="text-xs font-semibold text-gray-700 mb-2">Condition (optional) — only run this stage if:</p>
                            <div class="grid grid-cols-3 gap-3">
                                <select name="condition_field" class="text-xs border-gray-300 rounded-md">
                                    <option value="">— Always run —</option>
                                    <option value="brand_id">Brand</option>
                                    <option value="document_type_id">Document Type</option>
                                    <option value="category">Category</option>
                                    <option value="target_audience">Target Audience</option>
                                    <option value="department">Owner's Department</option>
                                </select>
                                <select name="condition_operator" class="text-xs border-gray-300 rounded-md">
                                    <option value="equals">equals</option>
                                    <option value="not_equals">not equals</option>
                                    <option value="in">is one of</option>
                                    <option value="not_in">is not one of</option>
                                </select>
                                <x-text-input name="condition_value" class="text-xs" placeholder="value, or a,b,c for 'is one of'" />
                            </div>
                            <p class="mt-1 text-xs text-gray-400">Brand/Document Type conditions use the record's ID — set this after checking Admin → Brands / Document Types.</p>
                        </div>
                        <div>
                            <x-input-label value="Approver Role(s)" />
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                @foreach ($roles as $role)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="role_ids[]" value="{{ $role->id }}" class="rounded border-gray-300 text-brand-600">
                                        {{ $role->name }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <x-primary-button>Add Stage</x-primary-button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
