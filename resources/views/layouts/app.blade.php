@props(['wide' => false, 'title' => null, 'flush' => false])
@php
    // Ingeklapte zijbalk onthouden zonder inline script: de keuze staat in een (niet-versleuteld) cookie.
    $navCollapsed = request()->cookie('pp_nav') === '1';
    $user = Auth::user();
    $canPlan = $user->canPlan();
@endphp
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
    <body class="bg-gray-50 font-sans text-gray-900 antialiased">
        @include('partials.icons')

        <div class="app-shell {{ $navCollapsed ? 'is-collapsed' : '' }}" x-data="appShell({{ $navCollapsed ? 'true' : 'false' }})"
             :class="{ 'is-collapsed': collapsed, 'is-open': open }" @keydown.escape.window="open = false">
            @include('layouts.navigation')
            <div class="app-nav-backdrop" @click="open = false"></div>

            <div class="app-main">
                <header class="app-topbar">
                    <button type="button" class="btn-icon lg:hidden" @click="open = true" aria-label="Menu openen"><x-icon name="menu" class="icon-lg" /></button>

                    @if ($canPlan)
                        <button type="button" class="search-trigger" data-open-palette aria-label="Zoeken">
                            <x-icon name="search" />
                            <span class="min-w-0 flex-1 truncate">Zoek medewerker of pand…</span>
                            <span class="kbd hidden sm:inline-flex">Ctrl K</span>
                        </button>
                    @else
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-700">{{ $title }}</span>
                    @endif

                    <div class="ms-auto flex items-center gap-1.5">
                        @if ($canPlan)
                            <div class="relative" x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape="menu = false">
                                <button type="button" class="btn" @click="menu = !menu" :aria-expanded="menu">
                                    <x-icon name="plus" /><span class="hidden sm:inline">Nieuw</span>
                                </button>
                                <div class="menu right-0" x-show="menu" x-cloak x-transition.origin.top.right>
                                    <a class="menu-item" href="{{ route('absences.create') }}"><x-icon name="user-x" />Uitval of afwezigheid melden</a>
                                    <a class="menu-item" href="{{ route('shifts.create') }}"><x-icon name="calendar" />Losse opdracht inplannen</a>
                                    <div class="menu-sep"></div>
                                    <a class="menu-item" href="{{ route('employees.create') }}"><x-icon name="user" />Medewerker toevoegen</a>
                                    <a class="menu-item" href="{{ route('buildings.create') }}"><x-icon name="building" />Pand toevoegen</a>
                                </div>
                            </div>
                        @endif

                        <div class="relative" x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape="menu = false">
                            <button type="button" class="flex items-center gap-2 rounded-lg p-1 pe-2 text-sm text-gray-700 hover:bg-gray-100" @click="menu = !menu" :aria-expanded="menu" aria-label="Accountmenu">
                                <x-avatar :name="$user->name" color="#0f766e" />
                                <span class="hidden max-w-[10rem] truncate font-medium md:inline">{{ $user->name }}</span>
                                <x-icon name="chevron-down" class="hidden text-gray-400 md:inline" />
                            </button>
                            <div class="menu right-0" x-show="menu" x-cloak x-transition.origin.top.right>
                                <div class="px-2.5 py-2">
                                    <p class="truncate text-sm font-medium text-gray-900">{{ $user->name }}</p>
                                    <p class="truncate text-xs text-gray-500">{{ $user->roleLabel() }} · {{ $user->email }}</p>
                                </div>
                                <div class="menu-sep"></div>
                                <a class="menu-item" href="{{ route('profile.edit') }}"><x-icon name="user" />Mijn profiel</a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="menu-item"><x-icon name="log-out" />Uitloggen</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                @isset($header)
                    <div class="page-head {{ $wide ? '' : 'page-narrow' }}">
                        {{ $header }}
                    </div>
                @endisset

                <main class="app-content {{ $wide ? '' : 'page-narrow' }} {{ $flush ? '!p-0' : '' }}">
                    @if (session('status'))
                        <div class="notice notice-success mb-4 {{ $flush ? 'mx-4 mt-4 sm:mx-6' : '' }}" x-data="{ show: true }" x-show="show" role="status">
                            <x-icon name="check-circle" class="mt-0.5 text-teal-600" />
                            <span class="flex-1">{{ session('status') }}</span>
                            <button type="button" @click="show = false" class="text-teal-700 hover:text-teal-900" aria-label="Sluiten"><x-icon name="x" /></button>
                        </div>
                    @endif
                    @if ($errors->any() && ! ($hideErrors ?? false))
                        <div class="notice notice-danger mb-4 {{ $flush ? 'mx-4 mt-4 sm:mx-6' : '' }}" role="alert">
                            <x-icon name="alert-circle" class="mt-0.5 text-red-600" />
                            <div>
                                <p class="font-medium">Controleer de invoer:</p>
                                <ul class="ms-5 mt-1 list-disc">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>

        @if ($canPlan)
            @include('partials.shift-panel')
            @include('partials.employee-panel')
            @include('partials.palette')
        @endif
    </body>
</html>
