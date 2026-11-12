@php
    $canPlan = Auth::user()->canPlan();
    $links = $canPlan ? [
        ['route' => 'dashboard', 'label' => 'Vandaag', 'active' => 'dashboard'],
        ['route' => 'planning', 'label' => 'Planning', 'active' => 'planning'],
        ['route' => 'calendar', 'label' => 'Kalender', 'active' => 'calendar'],
        ['route' => 'open-shifts', 'label' => 'Open diensten', 'active' => 'open-shifts', 'badge' => $openSlotCount ?? 0],
        ['route' => 'absences.index', 'label' => 'Afwezigheid', 'active' => 'absences.*'],
        ['route' => 'employees.index', 'label' => 'Medewerkers', 'active' => 'employees.*'],
        ['route' => 'buildings.index', 'label' => 'Panden', 'active' => 'buildings.*'],
    ] : (Auth::user()->isEmployee() ? [
        // Medewerkers met een eigen inlog.
        ['route' => 'my.planning', 'label' => 'Mijn planning', 'active' => 'my.planning'],
        ['route' => 'my.absences', 'label' => 'Verlof', 'active' => 'my.absences*'],
        ['route' => 'my.availability', 'label' => 'Beschikbaarheid', 'active' => 'my.availability*'],
    ] : []);
    $home = route(Auth::user()->homeRoute());
@endphp
<nav x-data="{ open: false }" class="bg-white border-b border-gray-200">
    <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-14">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ $home }}" class="flex items-center gap-2">
                        <x-application-logo class="block h-8 w-auto" />
                        <span class="font-semibold text-gray-800 tracking-tight">PoetsPloeg</span>
                    </a>
                </div>

                <div class="hidden space-x-4 lg:-my-px lg:ms-6 lg:flex xl:ms-10 xl:space-x-6">
                    @foreach ($links as $link)
                        <x-nav-link :href="route($link['route'])" :active="request()->routeIs($link['active'])">
                            {{ $link['label'] }}
                            @if (! empty($link['badge']))
                                <span class="ms-1.5 rounded-full bg-orange-500 px-1.5 py-0.5 text-[0.65rem] font-semibold leading-none text-white" title="Open plekken komende 14 dagen">{{ $link['badge'] }}</span>
                            @endif
                        </x-nav-link>
                    @endforeach
                </div>
            </div>

            <div class="hidden lg:flex lg:items-center lg:ms-4 gap-2 xl:gap-3">
                @if ($canPlan)
                <a href="{{ route('shifts.create') }}" class="inline-flex items-center whitespace-nowrap rounded-md bg-teal-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-700" title="Losse opdracht inplannen"><span class="xl:hidden">+</span><span class="hidden xl:inline">+ Opdracht</span></a>
                @endif
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none">
                            <div class="max-w-[7rem] truncate xl:max-w-[12rem]">{{ Auth::user()->name }}</div>
                            <svg class="ms-1 fill-current h-4 w-4" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        @if ($canPlan)
                            <x-dropdown-link :href="route('skills.index')">Vaardigheden</x-dropdown-link>
                            <x-dropdown-link :href="route('users.index')">Gebruikers</x-dropdown-link>
                            <x-dropdown-link :href="route('help.recurrence')">Hulp: herhaling met code</x-dropdown-link>
                        @endif
                        <x-dropdown-link :href="route('profile.edit')">Mijn profiel</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" data-submit-form>Uitloggen</x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center lg:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100" aria-label="Menu">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden lg:hidden">
        <div class="pt-2 pb-3 space-y-1">
            @foreach ($links as $link)
                <x-responsive-nav-link :href="route($link['route'])" :active="request()->routeIs($link['active'])">
                    {{ $link['label'] }}
                    @if (! empty($link['badge']))<span class="ms-1 rounded-full bg-orange-500 px-1.5 py-0.5 text-xs font-semibold text-white">{{ $link['badge'] }}</span>@endif
                </x-responsive-nav-link>
            @endforeach
            @if ($canPlan)
                <x-responsive-nav-link :href="route('shifts.create')">+ Losse opdracht</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('skills.index')">Vaardigheden</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('users.index')">Gebruikers</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('help.recurrence')">Hulp: herhaling met code</x-responsive-nav-link>
            @endif
        </div>
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">Mijn profiel</x-responsive-nav-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')" data-submit-form>Uitloggen</x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
