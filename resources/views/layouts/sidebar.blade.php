@php
    $navItems = [
        ['route' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home'],
        ['route' => 'documents.index', 'pattern' => 'documents.*', 'label' => 'Documents', 'icon' => 'document'],
        ['route' => 'library.folders.index', 'pattern' => 'library.*', 'label' => 'Library', 'icon' => 'book'],
        ['route' => 'projects.index', 'pattern' => 'projects.*', 'label' => 'Projects', 'icon' => 'folder'],
        ['route' => 'approvals.inbox', 'pattern' => 'approvals.*', 'label' => 'Approvals', 'icon' => 'check-circle', 'badge' => auth()->user()->pendingApprovals()->count()],
    ];

    if (auth()->user()->can('viewReports', \App\Models\Document::class)) {
        $navItems[] = ['route' => 'reports.index', 'pattern' => 'reports.*', 'label' => 'Reports', 'icon' => 'chart'];
    }
@endphp
@php
    $icons = [
        'home' => 'M3 11.5 12 4l9 7.5M5 10v9.25A.75.75 0 0 0 5.75 20H9v-5.5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1V20h3.25a.75.75 0 0 0 .75-.75V10',
        'document' => 'M8 3.5h5.5L18 8v11.25a.75.75 0 0 1-.75.75H8a.75.75 0 0 1-.75-.75V4.25A.75.75 0 0 1 8 3.5ZM13 3.5V8h4.5M10 12.5h6M10 15.5h6M10 9.5h2',
        'book' => 'M5 4.5c1.8-.9 4.2-.9 6 0v14c-1.8-.9-4.2-.9-6 0V4.5ZM19 4.5c-1.8-.9-4.2-.9-6 0v14c1.8-.9 4.2-.9 6 0V4.5Z',
        'folder' => 'M4 6.75A1.25 1.25 0 0 1 5.25 5.5h4.19c.3 0 .59.12.8.33l1.51 1.42h7a1.25 1.25 0 0 1 1.25 1.25v9.25a1.25 1.25 0 0 1-1.25 1.25H5.25A1.25 1.25 0 0 1 4 17.25V6.75Z',
        'check-circle' => 'M8.5 12.5l2.5 2.5 5-5.5M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
        'chart' => 'M5 19.5V10m6.5 9.5V4.5M18 19.5V13',
        'help' => 'M9.5 9a2.5 2.5 0 1 1 3.9 2.07c-.66.46-1.4 1.05-1.4 1.93v.25M12 17h.01M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
        'shield' => 'M12 3.5l7 2.5v5.5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-2.5Z',
    ];
@endphp
{{--
    Deliberately no x-data here: sidebarOpen lives on the outer wrapper in
    layouts/app.blade.php so this partial and layouts/topbar.blade.php's hamburger
    button share one Alpine scope. Giving this its own x-data would create a nested,
    independent scope that shadows the parent - the topbar's button would then be
    toggling a `sidebarOpen` this <aside> never sees.
--}}
<div>

    <!-- Mobile overlay -->
    <div x-show="sidebarOpen" x-cloak x-transition.opacity @click="sidebarOpen = false"
         class="fixed inset-0 bg-gray-900/50 z-40 lg:hidden"></div>

    <!-- Sidebar -->
    <aside
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        class="fixed inset-y-0 left-0 z-50 w-64 flex flex-col transition-transform duration-200 ease-in-out lg:translate-x-0"
        style="background: linear-gradient(190deg, #0f5d61 0%, #0b3f42 100%);">

        <div class="h-16 flex items-center gap-2.5 px-5 shrink-0">
            <span class="inline-flex bg-white rounded-lg p-1">
                <x-application-logo class="w-7 h-7" />
            </span>
            <span class="text-white font-bold text-lg tracking-tight">VODO</span>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">
            @foreach ($navItems as $item)
                @php $active = request()->routeIs($item['pattern']); @endphp
                <a href="{{ route($item['route']) }}"
                   class="group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $active ? 'bg-white/10 text-white' : 'text-teal-100/70 hover:bg-white/5 hover:text-white' }}">
                    <svg class="w-[18px] h-[18px] shrink-0 {{ $active ? 'text-accent-400' : 'text-teal-100/50 group-hover:text-teal-100' }}" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$item['icon']] }}" />
                    </svg>
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if (($item['badge'] ?? 0) > 0)
                        <span class="text-[11px] font-bold px-1.5 py-0.5 rounded-full bg-accent-500 text-white leading-none">{{ $item['badge'] }}</span>
                    @endif
                </a>
            @endforeach

            @can('access-admin')
                <div class="pt-4 mt-4 border-t border-white/10">
                    <a href="{{ route('admin.workflows.index') }}"
                       class="group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('admin.*') ? 'bg-white/10 text-white' : 'text-teal-100/70 hover:bg-white/5 hover:text-white' }}">
                        <svg class="w-[18px] h-[18px] shrink-0 {{ request()->routeIs('admin.*') ? 'text-accent-400' : 'text-teal-100/50 group-hover:text-teal-100' }}" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['shield'] }}" />
                        </svg>
                        <span>Admin</span>
                    </a>
                </div>
            @endcan
        </nav>

        <div class="px-3 pb-3">
            <a href="{{ route('help.index') }}"
               class="group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('help.*') ? 'bg-white/10 text-white' : 'text-teal-100/70 hover:bg-white/5 hover:text-white' }}">
                <svg class="w-[18px] h-[18px] shrink-0 {{ request()->routeIs('help.*') ? 'text-accent-400' : 'text-teal-100/50 group-hover:text-teal-100' }}" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['help'] }}" />
                </svg>
                <span>Help &amp; Demo Guide</span>
            </a>
        </div>

        <div class="px-3 py-3 border-t border-white/10">
            <x-dropdown align="left" width="w-56" direction="up">
                <x-slot name="trigger">
                    <button class="w-full flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-white/5 transition-colors text-left">
                        <span class="w-8 h-8 rounded-full bg-accent-500 text-white text-xs font-bold flex items-center justify-center shrink-0">
                            {{ collect(explode(' ', Auth::user()->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
                        </span>
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-medium text-white truncate">{{ Auth::user()->name }}</span>
                            <span class="block text-xs text-teal-100/60 truncate">{{ Auth::user()->email }}</span>
                        </span>
                        <svg class="w-4 h-4 text-teal-100/50 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4M8 15l4 4 4-4" /></svg>
                    </button>
                </x-slot>
                <x-slot name="content">
                    <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                            {{ __('Log Out') }}
                        </x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>
    </aside>
</div>
