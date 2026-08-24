<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @include('admin._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-1">Workflow Rules</h3>
                <p class="text-sm text-gray-500">
                    Which workflow a new document gets, resolved from Brand + Document Type + Department — data, not
                    code (see <span class="font-mono text-xs">App\Services\Workflow\WorkflowResolver</span>). Leave
                    Brand, Document Type, or Department blank on a rule to match anything for that field. Lower
                    priority number is evaluated first; a more specific rule beats a general one at the same priority.
                </p>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg overflow-hidden mb-6">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Priority</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Brand</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Document Type</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Department</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Workflow</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rules as $rule)
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $rule->priority }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $rule->brand?->name ?? 'Any' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $rule->documentType?->name ?? 'Any' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $rule->department ?? 'Any' }}</td>
                                <td class="px-4 py-3 text-gray-900 font-medium">{{ $rule->template?->name }}</td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('admin.workflow-rules.destroy', $rule) }}" onsubmit="return confirm('Remove this rule?');" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No rules yet — documents fall back to a manually-picked workflow until one exists.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Add Rule</h3>
                <form method="POST" action="{{ route('admin.workflow-rules.store') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
                    @csrf
                    <div>
                        <x-input-label for="brand_id" value="Brand" />
                        <select id="brand_id" name="brand_id" class="mt-1 block w-full border-gray-300 rounded-md text-sm">
                            <option value="">Any</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="document_type_id" value="Document Type" />
                        <select id="document_type_id" name="document_type_id" class="mt-1 block w-full border-gray-300 rounded-md text-sm">
                            <option value="">Any</option>
                            @foreach ($documentTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="department" value="Department" />
                        <x-text-input id="department" name="department" class="mt-1 block w-full" placeholder="Any" />
                    </div>
                    <div>
                        <x-input-label for="workflow_template_id" value="Workflow" />
                        <select id="workflow_template_id" name="workflow_template_id" required class="mt-1 block w-full border-gray-300 rounded-md text-sm">
                            <option value="">Select…</option>
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="priority" value="Priority" />
                        <x-text-input type="number" id="priority" name="priority" class="mt-1 block w-full" value="100" required />
                    </div>
                    <div class="md:col-span-5">
                        <x-primary-button>Add Rule</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
