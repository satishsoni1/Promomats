@php
    $unreadNotifications = auth()->user()->unreadNotifications()->latest()->limit(8)->get();
    $unreadCount = auth()->user()->unreadNotifications()->count();
    $basketCount = count(session('download_basket', []));
@endphp
<header class="min-h-16 shrink-0 flex items-center gap-3 px-4 sm:px-6 py-2.5 bg-white border-b border-gray-200">
    <button @click="sidebarOpen = true" class="lg:hidden p-2 -ml-2 text-gray-500 hover:text-gray-700">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
    </button>

    <div class="flex-1 min-w-0">
        @isset($header)
            {{ $header }}
        @endisset
    </div>

    <form method="GET" action="{{ route('search') }}" class="relative hidden md:block">
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Search documents, claims…"
               class="w-64 text-sm border-gray-300 rounded-full pl-9 pr-3 py-1.5 focus:border-brand-500 focus:ring-brand-500">
        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" />
        </svg>
    </form>

    <!-- Notification bell -->
    <x-dropdown align="right" width="w-96">
        <x-slot name="trigger">
            <button class="relative p-2 text-gray-500 hover:text-gray-700" title="Notifications">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 8a6 6 0 1 1 12 0c0 4.5 1.5 6 2 6.5H4c.5-.5 2-2 2-6.5Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 17.5a2.5 2.5 0 0 0 5 0" />
                </svg>
                @if ($unreadCount > 0)
                    <span class="absolute top-1 right-1 w-4 h-4 flex items-center justify-center text-[10px] font-bold text-white bg-accent-500 rounded-full">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
                @endif
            </button>
        </x-slot>
        <x-slot name="content">
            <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100">
                <span class="text-sm font-semibold text-gray-900">Notifications</span>
                @if ($unreadCount > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button class="text-xs text-brand-600 hover:underline">Mark all read</button>
                    </form>
                @endif
            </div>
            <div class="max-h-96 overflow-y-auto">
                @forelse ($unreadNotifications as $notification)
                    <a href="{{ route('notifications.open', $notification->id) }}"
                       class="block px-4 py-3 border-b border-gray-50 last:border-b-0 hover:bg-gray-50 text-sm">
                        <p class="text-gray-800">{{ $notification->data['message'] ?? 'New activity' }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $notification->created_at->diffForHumans() }}</p>
                    </a>
                @empty
                    <p class="px-4 py-6 text-sm text-gray-500 text-center">You're all caught up.</p>
                @endforelse
            </div>
        </x-slot>
    </x-dropdown>

    <a href="{{ route('basket.show') }}" class="relative p-2 text-gray-500 hover:text-gray-700" title="Download basket">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l3-8H6.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
        </svg>
        @if ($basketCount > 0)
            <span class="absolute -top-1 -right-1 inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold text-white bg-accent-500 rounded-full">{{ $basketCount }}</span>
        @endif
    </a>
</header>
