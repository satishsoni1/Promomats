<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'VODO') }}</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('images/vodo-logo.svg') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex">

            <!-- Brand panel -->
            <div class="hidden lg:flex lg:w-[42%] relative flex-col justify-between p-12 text-white overflow-hidden"
                 style="background: linear-gradient(155deg, #0f5d61 0%, #127277 55%, #1f9598 100%);">
                <div class="absolute -right-24 -top-24 w-96 h-96 rounded-full bg-white/5"></div>
                <div class="absolute -left-16 bottom-0 w-72 h-72 rounded-full bg-white/5"></div>
                <div class="absolute right-10 top-1/3 w-3 h-3 bg-accent-400/70 rotate-12"></div>
                <div class="absolute right-24 top-[42%] w-2 h-2 bg-white/40 rotate-12"></div>

                <a href="/" class="relative z-10 flex items-center gap-3">
                    <span class="inline-flex bg-white rounded-lg p-1.5">
                        <x-application-logo class="w-8 h-8" />
                    </span>
                    <span class="text-xl font-bold tracking-tight">VODO</span>
                </a>

                <div class="relative z-10 space-y-6 max-w-sm">
                    <h1 class="text-3xl font-bold leading-tight">Document approval, version control &amp; compliance — in one place.</h1>
                    <p class="text-white/80 text-sm leading-relaxed">
                        Configurable multi-role approval workflows, full version history,
                        claims &amp; reference management, and audit-ready records for every
                        piece of promotional material.
                    </p>
                    <ul class="space-y-3 text-sm text-white/90">
                        <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-accent-400"></span> Any file type, any size, fully versioned</li>
                        <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-accent-400"></span> Admin-defined approval chains — A / AwC / NA</li>
                        <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-accent-400"></span> Notifications on every action, every stage</li>
                    </ul>
                </div>

                <p class="relative z-10 text-xs text-white/50">&copy; {{ date('Y') }} VODO. All rights reserved.</p>
            </div>

            <!-- Form panel -->
            <div class="flex-1 flex flex-col items-center justify-center px-6 py-12 bg-gray-50">
                <div class="w-full max-w-sm">
                    <div class="flex lg:hidden items-center gap-2 justify-center mb-8">
                        <x-application-logo class="w-9 h-9" />
                        <span class="text-lg font-bold text-gray-900">VODO</span>
                    </div>

                    <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-2xl p-8">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
