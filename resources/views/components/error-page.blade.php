@props(['code', 'title', 'message'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $code }} — {{ $title }} · {{ config('app.name', 'VODO') }}</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('images/vodo-logo.svg') }}">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex items-center justify-center px-6" style="background: radial-gradient(circle at 15% 15%, #eafaf9 0%, #f8faf9 45%, #ffffff 100%);">
            <div class="max-w-md w-full text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl mb-6" style="background: linear-gradient(155deg, #0f5d61 0%, #1f9598 100%);">
                    <span class="text-2xl font-bold text-white">{{ $code }}</span>
                </div>
                <h1 class="text-xl font-semibold text-gray-900 mb-2">{{ $title }}</h1>
                <p class="text-sm text-gray-500 leading-relaxed mb-8">{{ $message }}</p>
                <div class="flex items-center justify-center gap-3">
                    <a href="{{ url('/') }}" class="inline-flex items-center px-4 py-2 bg-brand-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-700">
                        Go to dashboard
                    </a>
                    <button onclick="history.back()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-600 uppercase tracking-widest hover:border-gray-400">
                        Go back
                    </button>
                </div>
                {{ $slot ?? '' }}
            </div>
        </div>
    </body>
</html>
