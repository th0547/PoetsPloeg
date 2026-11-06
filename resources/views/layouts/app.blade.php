@props(['wide' => false, 'title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ? $title.' – ' : '' }}{{ config('app.name', 'PoetsPloeg') }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{ $scripts ?? '' }}
    </head>
    <body class="font-sans antialiased text-gray-900">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.navigation')

            @isset($header)
                <header class="bg-white shadow-sm">
                    <div class="{{ $wide ? 'max-w-none' : 'max-w-7xl' }} mx-auto py-4 px-4 sm:px-6 lg:px-8 flex flex-wrap items-center justify-between gap-3">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main class="{{ $wide ? 'max-w-none' : 'max-w-7xl' }} mx-auto py-6 px-4 sm:px-6 lg:px-8">
                @if (session('status'))
                    <div class="mb-4 rounded-md bg-teal-50 border border-teal-200 px-4 py-3 text-sm text-teal-800" x-data="{ show: true }" x-show="show">
                        <div class="flex justify-between gap-4">
                            <span>{{ session('status') }}</span>
                            <button type="button" @click="show = false" class="text-teal-600 hover:text-teal-900">&times;</button>
                        </div>
                    </div>
                @endif
                @if ($errors->any() && ! ($hideErrors ?? false))
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                        <p class="font-medium">Controleer de invoer:</p>
                        <ul class="list-disc ms-5 mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>

        @include('partials.shift-panel')
    </body>
</html>
