<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'PoetsPloeg') }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

        {{-- Het lettertype zit in de eigen bundel (resources/css/app.css): geen externe bron nodig. --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-50 font-sans text-gray-900 antialiased">
        @include('partials.icons')
        <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
            <a href="/" class="flex items-center gap-3">
                <x-application-logo class="h-11 w-11" />
                <span class="text-xl font-semibold tracking-tight text-gray-900">{{ config('app.name', 'PoetsPloeg') }}</span>
            </a>

            <div class="card mt-6 w-full max-w-md px-6 py-6">
                {{ $slot }}
            </div>
            <p class="mt-6 text-xs text-gray-400">Planning voor schoonmaak</p>
        </div>
    </body>
</html>
