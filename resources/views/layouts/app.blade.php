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
    <body class="font-sans antialiased bg-gray-50">
        <div x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false" class="min-h-screen">
            @include('layouts.sidebar')

            <div class="lg:pl-64 flex flex-col min-h-screen">
                @include('layouts.topbar')

                <main class="flex-1">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-toast-container />
    </body>
</html>
