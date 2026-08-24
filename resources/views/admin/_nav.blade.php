<div class="flex gap-4 border-b border-gray-200 mb-6 text-sm overflow-x-auto">
    <a href="{{ route('admin.dashboards.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.dashboards.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Dashboards</a>
    <a href="{{ route('admin.workflows.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.workflows.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Workflows</a>
    <a href="{{ route('admin.workflow-rules.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.workflow-rules.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Workflow Rules</a>
    <a href="{{ route('admin.brands.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.brands.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Brands</a>
    <a href="{{ route('admin.document-types.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.document-types.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Document Types</a>
    <a href="{{ route('admin.claims.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.claims.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Claims</a>
    <a href="{{ route('admin.claim-candidates.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.claim-candidates.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">
        🤖 Claim Candidates
        @if (($pendingClaimCandidates ?? 0) > 0)
            <span class="ml-1 inline-flex items-center justify-center text-[10px] font-bold bg-accent-500 text-white rounded-full w-4 h-4">{{ $pendingClaimCandidates }}</span>
        @endif
    </a>
    <a href="{{ route('admin.content-modules.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.content-modules.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Content Modules</a>
    <a href="{{ route('admin.users.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.users.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Users</a>
    <a href="{{ route('admin.roles.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.roles.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Roles</a>
    <a href="{{ route('admin.mail-settings.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.mail-settings.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Mail Settings</a>
    <a href="{{ route('admin.ai-settings.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.ai-settings.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">🤖 AI Settings</a>
    <a href="{{ route('admin.archiving-settings.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.archiving-settings.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Archiving</a>
    <a href="{{ route('admin.cold-storage-settings.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.cold-storage-settings.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Cold Storage</a>
    <a href="{{ route('admin.retrieval-requests.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.retrieval-requests.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">
        Retrieval Requests
        @if (($pendingRetrievalRequests ?? 0) > 0)
            <span class="ml-1 inline-flex items-center justify-center text-[10px] font-bold bg-accent-500 text-white rounded-full w-4 h-4">{{ $pendingRetrievalRequests }}</span>
        @endif
    </a>
    <a href="{{ route('admin.audit-logs.index') }}" class="pb-2 px-1 whitespace-nowrap {{ request()->routeIs('admin.audit-logs.*') ? 'border-b-2 border-brand-600 text-brand-600 font-medium' : 'text-gray-500' }}">Audit Logs</a>
</div>
