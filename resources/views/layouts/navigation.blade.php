@php
    $user = Auth::user();
    $canPlan = $user->canPlan();
    $is = fn (string ...$patterns) => request()->routeIs(...$patterns);
    $showPolicy = \App\Support\Policy::current()->get('privacy.visible_to_employees');
@endphp
<aside class="app-nav" aria-label="Hoofdmenu">
    <a href="{{ route($user->homeRoute()) }}" class="app-brand">
        <x-application-logo class="h-8 w-8 shrink-0" />
        <span class="app-brand-name">{{ config('app.name', 'PoetsPloeg') }}</span>
    </a>

    <nav class="nav-scroll">
        @if ($canPlan)
            <div>
                <p class="nav-group-title">Planning</p>
                <div class="space-y-0.5">
                    <x-nav-item :href="route('dashboard')" icon="sun" :active="$is('dashboard')">Vandaag</x-nav-item>
                    <x-nav-item :href="route('planning')" icon="grid" :active="$is('planning')">Planning</x-nav-item>
                    <x-nav-item :href="route('open-shifts')" icon="inbox" :active="$is('open-shifts')"
                                :badge="($openSlotCount ?? 0) ?: null" badge-title="Open plekken komende 14 dagen">Open diensten</x-nav-item>
                    <x-nav-item :href="route('absences.index')" icon="user-x" :active="$is('absences.*')"
                                :badge="($pendingRequestCount ?? 0) ?: null" badge-title="Verlofverzoeken die op een vervanger wachten" badge-class="nav-badge-amber">Afwezigheid</x-nav-item>
                </div>
            </div>
            <div>
                <p class="nav-group-title">Beheer</p>
                <div class="space-y-0.5">
                    <x-nav-item :href="route('employees.index')" icon="users" :active="$is('employees.*')">Medewerkers</x-nav-item>
                    <x-nav-item :href="route('buildings.index')" icon="building" :active="$is('buildings.*')">Panden</x-nav-item>
                    <x-nav-item :href="route('skills.index')" icon="star" :active="$is('skills.*')">Vaardigheden</x-nav-item>
                    <x-nav-item :href="route('availability.overview')" icon="check-square" :active="$is('availability.overview')">Beschikbaarheid</x-nav-item>
                </div>
            </div>
            <div>
                <p class="nav-group-title">Instellingen</p>
                <div class="space-y-0.5">
                    <x-nav-item :href="route('users.index')" icon="key" :active="$is('users.*')">Gebruikers</x-nav-item>
                    <x-nav-item :href="route('policy.index')" icon="book" :active="$is('policy.*')">Beleid</x-nav-item>
                    @can('view-audit-log')
                        <x-nav-item :href="route('audit-log')" icon="list" :active="$is('audit-log')">Logboek</x-nav-item>
                    @endcan
                    <x-nav-item :href="route('help.recurrence')" icon="help" :active="$is('help.*')">Hulp: herhaling</x-nav-item>
                </div>
            </div>
        @elseif ($user->isEmployee())
            <div class="space-y-0.5">
                <x-nav-item :href="route('my.planning')" icon="calendar" :active="$is('my.planning')">Mijn planning</x-nav-item>
                <x-nav-item :href="route('my.absences')" icon="sun" :active="$is('my.absences*')">Verlof</x-nav-item>
                <x-nav-item :href="route('my.availability')" icon="check-square" :active="$is('my.availability*')">Beschikbaarheid</x-nav-item>
                @if ($showPolicy)
                    <x-nav-item :href="route('my.policy')" icon="book" :active="$is('my.policy')">Regels</x-nav-item>
                @endif
            </div>
        @endif
    </nav>

    <div class="nav-foot">
        <button type="button" class="nav-item nav-collapse hidden w-full lg:flex" @click="toggle()" :title="collapsed ? 'Menu uitklappen' : 'Menu inklappen'">
            <x-icon name="chevrons-left" class="transition-transform" />
            <span class="nav-label text-left">Menu inklappen</span>
        </button>
        <button type="button" class="nav-item w-full lg:hidden" @click="open = false">
            <x-icon name="x" />
            <span class="nav-label text-left">Menu sluiten</span>
        </button>
    </div>
</aside>
