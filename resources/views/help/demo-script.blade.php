@php
    $demoPassword = \Database\Seeders\DemoUsersSeeder::DEMO_PASSWORD;
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Help') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('help._nav')

            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Client Demo Script</h3>
                <p class="text-sm text-gray-700">
                    A ready-to-run walkthrough using the credentials and demo documents already seeded in
                    this system — no setup needed before a client call. Print this page or share the link;
                    it always reflects the system's current live state.
                </p>
            </div>

            <!-- Credentials -->
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">Login Credentials</h3>
                <p class="text-sm text-gray-500 mb-3">
                    Password for every demo account: <code class="bg-gray-100 px-1.5 py-0.5 rounded text-brand-700 font-mono">{{ $demoPassword }}</code>
                    &nbsp;&middot;&nbsp; Admin: <code class="bg-gray-100 px-1.5 py-0.5 rounded font-mono">admin@example.com</code> / <code class="bg-gray-100 px-1.5 py-0.5 rounded font-mono">ChangeMe123!</code>
                </p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b border-gray-200">
                                <th class="py-2 pr-4">Role</th>
                                <th class="py-2 pr-4">Email</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($demoUsers as $user)
                                <tr>
                                    <td class="py-1.5 pr-4 text-gray-700">{{ $user->roles->pluck('name')->implode(', ') }}</td>
                                    <td class="py-1.5 pr-4 font-mono text-xs text-gray-600">{{ $user->email }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Workflows -->
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">Workflows Configured</h3>
                <div class="space-y-2 text-sm">
                    @foreach ($workflows as $wf)
                        <div class="flex items-center justify-between border-b border-gray-100 last:border-b-0 py-1.5">
                            <span class="text-gray-800">{{ $wf->name }}</span>
                            <span class="text-xs text-gray-500">{{ $wf->stages_count }} stage(s)</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Live demo documents -->
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">Demo Documents Already in the System</h3>
                <div class="space-y-2">
                    @forelse ($demoDocuments as $doc)
                        <a href="{{ route('documents.show', $doc) }}" class="flex items-center justify-between py-2 border-b last:border-b-0 border-gray-100 text-sm hover:bg-gray-50 -mx-2 px-2 rounded">
                            <div>
                                <span class="font-medium text-gray-900">{{ $doc->title }}</span>
                                <span class="text-gray-500 font-mono text-xs ml-1">{{ $doc->reference_no }}</span>
                                <div class="text-xs text-gray-500">Owner: {{ $doc->owner?->name }} &middot; Workflow: {{ $doc->workflowTemplate?->name }}</div>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 whitespace-nowrap">
                                {{ $doc->statusLabel() }}
                                @if ($doc->activeWorkflowInstance?->currentStage)
                                    &middot; {{ $doc->activeWorkflowInstance->currentStage->name }}
                                @endif
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No demo documents yet — upload one via Documents → Upload Document to seed this list.</p>
                    @endforelse
                </div>
            </div>

            <!-- Suggested script -->
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Suggested 15-Minute Walkthrough</h3>
                <ol class="space-y-4 text-sm">
                    <li class="flex gap-3">
                        <span class="shrink-0 w-6 h-6 rounded-full bg-brand-600 text-white text-xs font-bold flex items-center justify-center">1</span>
                        <div>
                            <p class="font-medium text-gray-900">Log in as the Document Owner</p>
                            <p class="text-gray-600">Show the dashboard — pending approvals, recent documents, aging/expiry stats.</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="shrink-0 w-6 h-6 rounded-full bg-brand-600 text-white text-xs font-bold flex items-center justify-center">2</span>
                        <div>
                            <p class="font-medium text-gray-900">Upload a new document</p>
                            <p class="text-gray-600">Any file type/size, tag it with a product and country, assign a workflow. Point out the auto-generated, country/category-coded reference number.</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="shrink-0 w-6 h-6 rounded-full bg-brand-600 text-white text-xs font-bold flex items-center justify-center">3</span>
                        <div>
                            <p class="font-medium text-gray-900">Submit it, then switch users to approve</p>
                            <p class="text-gray-600">Log out, log in as the next role in the chain (e.g. Content Manager), open the Approvals Inbox, record an Approved decision with the stage-progress bar visible.</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="shrink-0 w-6 h-6 rounded-full bg-brand-600 text-white text-xs font-bold flex items-center justify-center">4</span>
                        <div>
                            <p class="font-medium text-gray-900">Open "{{ $demoDocuments->firstWhere('status', 'in_review')?->title ?? 'a document mid-workflow' }}"</p>
                            <p class="text-gray-600">Already sitting mid-approval — shows the stage progress bar, claims panel, and comment thread live without any setup.</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="shrink-0 w-6 h-6 rounded-full bg-brand-600 text-white text-xs font-bold flex items-center justify-center">5</span>
                        <div>
                            <p class="font-medium text-gray-900">Demonstrate a revision loop (Approved with Changes)</p>
                            <p class="text-gray-600">As an approver, choose "Approved with Changes" with a comment. Log back in as the owner, upload a new version with change notes, and resubmit — show it resume automatically at the right stage, and the full version history on the document page.</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="shrink-0 w-6 h-6 rounded-full bg-brand-600 text-white text-xs font-bold flex items-center justify-center">6</span>
                        <div>
                            <p class="font-medium text-gray-900">Upload a PDF and try inline annotation</p>
                            <p class="text-gray-600">Click anywhere on the page preview to drop a comment pin; reply to it from another account to show the notification.</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="shrink-0 w-6 h-6 rounded-full bg-brand-600 text-white text-xs font-bold flex items-center justify-center">7</span>
                        <div>
                            <p class="font-medium text-gray-900">Show the Claims library</p>
                            <p class="text-gray-600">Open Admin → Claims, show a claim's references and "Where Used", then insert the pre-built content module into a document in one click.</p>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="shrink-0 w-6 h-6 rounded-full bg-brand-600 text-white text-xs font-bold flex items-center justify-center">8</span>
                        <div>
                            <p class="font-medium text-gray-900">Wrap up on Admin</p>
                            <p class="text-gray-600">Show Admin → Dashboards (charts), Admin → Workflows (drag-free stage/transition editor), and Admin → Users (create a user, assign a role) to demonstrate configurability.</p>
                        </div>
                    </li>
                </ol>
            </div>
        </div>
    </div>
</x-app-layout>
