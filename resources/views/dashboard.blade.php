<x-app-layout title="Vandaag">
    <x-slot name="header">
        <div>
            <h1 class="page-title">
                {{ $isToday ? 'Vandaag' : 'Dagoverzicht' }}
                <span class="font-normal text-gray-500">· {{ $day->translatedFormat('l j F Y') }}</span>
            </h1>
            @if ($holiday)
                <p class="text-sm text-amber-700">🎉 {{ $holiday }}</p>
            @endif
        </div>
        <div class="flex items-center gap-1.5">
            <a class="btn-icon border border-gray-300 bg-white" href="{{ route('dashboard', ['datum' => $day->subDay()->toDateString()]) }}" aria-label="Vorige dag"><x-icon name="chevron-left" /></a>
            <a class="btn-secondary !py-1.5" href="{{ route('dashboard') }}">Vandaag</a>
            <a class="btn-icon border border-gray-300 bg-white" href="{{ route('dashboard', ['datum' => $day->addDay()->toDateString()]) }}" aria-label="Volgende dag"><x-icon name="chevron-right" /></a>
            <form method="GET" action="{{ route('dashboard') }}">
                <input type="date" name="datum" value="{{ $day->toDateString() }}" data-auto-submit class="input mt-0 !w-auto !py-1.5" aria-label="Datum">
            </form>
            <a class="btn-secondary !py-1.5" href="{{ route('planning', ['view' => 'employee', 'range' => 'day', 'date' => $day->toDateString()]) }}"><x-icon name="grid" /><span class="hidden sm:inline">Tijdlijn</span></a>
        </div>
    </x-slot>

    <div x-data @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()">
        {{-- Kerncijfers --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <a href="{{ route('planning', ['view' => 'employee', 'range' => 'day', 'date' => $day->toDateString()]) }}" class="card card-body hover:border-teal-300">
                @if ($isToday)
                    <p class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-gray-500"><span class="dot {{ $workingNow ? 'dot-working' : '' }}"></span>Nu aan het werk</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $workingNow }} <span class="text-sm font-normal text-gray-500">{{ $workingNow === 1 ? 'medewerker' : 'medewerkers' }}</span></p>
                    <p class="text-xs text-gray-500">{{ $shiftCount }} {{ $shiftCount === 1 ? 'dienst' : 'diensten' }} vandaag</p>
                @else
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Diensten deze dag</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $shiftCount }}</p>
                    <p class="text-xs text-gray-500">{{ $openToday ? $openToday.' open '.($openToday === 1 ? 'plek' : 'plekken') : 'alles bezet' }}</p>
                @endif
            </a>
            <a href="{{ route('open-shifts') }}" class="card card-body hover:border-teal-300">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Open plekken (14 dagen)</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums {{ $openSlotCount ? 'text-orange-600' : 'text-teal-700' }}">{{ $openSlotCount }}</p>
                <p class="text-xs text-gray-500">{{ $openSlotCount ? 'vervangers zoeken' : 'alles is bezet' }}</p>
            </a>
            <a href="{{ route('absences.index') }}" class="card card-body hover:border-teal-300">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Afwezig</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $absentToday->count() }}</p>
                <p class="text-xs text-gray-500">{{ $pendingRequests->count() ? $pendingRequests->count().' '.($pendingRequests->count() === 1 ? 'verlofverzoek wacht' : 'verlofverzoeken wachten') : 'geen verzoeken open' }}</p>
            </a>
            <a href="{{ route('planning', ['view' => 'employee', 'range' => 'day', 'date' => $day->toDateString()]) }}" class="card card-body hover:border-teal-300">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Waarschuwingen deze dag</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums {{ $warningCount ? 'text-amber-600' : 'text-teal-700' }}">{{ $warningCount }}</p>
                <p class="text-xs text-gray-500">{{ $warningCount ? 'dubbel, afwezig of te veel uren' : 'niets aan de hand' }}</p>
            </a>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            {{-- Dagagenda --}}
            <div class="space-y-5 lg:col-span-2">
                @if ($isToday)
                    <section class="card overflow-hidden">
                        <div class="card-head">
                            <h2 class="card-title flex items-center gap-2"><span class="dot {{ $current->isNotEmpty() ? 'dot-working' : '' }}"></span>Nu aan het werk
                                <span class="font-normal text-gray-500">· {{ $now->format('H:i') }}</span></h2>
                            <span class="text-xs text-gray-500">klik op een naam voor zijn of haar dag</span>
                        </div>
                        <ul class="divide-y divide-gray-100">
                            @forelse ($current as $shift)
                                @include('partials.day-shift', ['shift' => $shift, 'state' => 'now'])
                            @empty
                                <li class="px-4 py-5 text-sm text-gray-500">Op dit moment is er niemand aan het werk.</li>
                            @endforelse
                        </ul>
                    </section>
                @endif

                <section class="card overflow-hidden">
                    <div class="card-head">
                        <h2 class="card-title">{{ $isToday ? 'Straks' : 'Diensten' }} <span class="font-normal text-gray-500">({{ $later->count() }})</span></h2>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        @forelse ($later as $shift)
                            @include('partials.day-shift', ['shift' => $shift, 'state' => 'later'])
                        @empty
                            <li class="px-4 py-5 text-sm text-gray-500">{{ $isToday ? 'Er komen vandaag geen diensten meer.' : 'Geen diensten op deze dag.' }}</li>
                        @endforelse
                    </ul>
                </section>

                @if ($finished->isNotEmpty())
                    <section class="card overflow-hidden" x-data="{ open: false }">
                        <button type="button" class="card-head w-full text-left !border-b-0" @click="open = !open" :aria-expanded="open">
                            <h2 class="card-title">Afgerond <span class="font-normal text-gray-500">({{ $finished->count() }})</span></h2>
                            <x-icon name="chevron-down" class="text-gray-400 transition-transform" ::class="open ? 'rotate-180' : ''" />
                        </button>
                        <ul class="divide-y divide-gray-100 border-t border-gray-200" x-show="open" x-cloak>
                            @foreach ($finished as $shift)
                                @include('partials.day-shift', ['shift' => $shift, 'state' => 'done'])
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>

            {{-- Actie nodig --}}
            <aside class="space-y-5">
                <section class="card overflow-hidden">
                    <div class="card-head">
                        <h2 class="card-title">Open diensten</h2>
                        <span class="text-xs text-gray-500">komende 14 dagen</span>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        @forelse ($openShifts->take(8) as $shift)
                            <li class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm" style="border-left: 4px solid {{ $shift->building->color }}">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ $shift->building->name }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $shift->starts_at->isToday() ? 'vandaag' : ($shift->starts_at->isTomorrow() ? 'morgen' : $shift->starts_at->translatedFormat('D j M')) }},
                                        {{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}
                                        · <span class="font-medium text-orange-700">{{ $shift->required_staff - $shift->assignments_count }} open</span>
                                    </p>
                                </div>
                                <button type="button" class="btn btn-sm shrink-0" data-open-shift="{{ $shift->id }}" data-candidates>Zoek vervanger</button>
                            </li>
                        @empty
                            <li class="flex items-center gap-2 px-4 py-4 text-sm text-teal-700"><x-icon name="check-circle" />Alles is bezet.</li>
                        @endforelse
                    </ul>
                    @if ($openShifts->count() > 8)
                        <a href="{{ route('open-shifts') }}" class="block border-t border-gray-100 px-4 py-2.5 text-sm font-medium text-teal-700 hover:bg-gray-50">Alle {{ $openShifts->count() }} open diensten <x-icon name="arrow-right" class="icon-sm" /></a>
                    @endif
                </section>

                @if ($pendingRequests->isNotEmpty())
                    <section class="card overflow-hidden">
                        <div class="card-head">
                            <h2 class="card-title">Verlofverzoeken</h2>
                            <span class="badge badge-amber">{{ $pendingRequests->count() }} wachten</span>
                        </div>
                        <ul class="divide-y divide-gray-100">
                            @foreach ($pendingRequests->take(6) as $request)
                                <li>
                                    <a href="{{ route('absences.show', $request) }}" class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm hover:bg-gray-50">
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium">{{ $request->employee->name }} <span class="font-normal text-gray-500">· {{ mb_strtolower($request->typeLabel()) }}</span></span>
                                            <span class="block text-xs text-gray-500">{{ $request->periodLabel() }} · {{ $request->shifts_count }} {{ $request->shifts_count === 1 ? 'dienst' : 'diensten' }}</span>
                                        </span>
                                        <span class="link shrink-0 text-xs">Vervangers</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="card overflow-hidden">
                    <div class="card-head">
                        <h2 class="card-title">Afwezig {{ $isToday ? 'vandaag' : 'deze dag' }}</h2>
                        <a href="{{ route('absences.create') }}" class="link text-sm">+ Uitval melden</a>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        @forelse ($absentToday as $absence)
                            <li class="flex items-center gap-2.5 px-4 py-2.5 text-sm">
                                <x-avatar :name="$absence->employee->name" :color="$absence->employee->color" size="sm" />
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('absences.show', $absence) }}" class="font-medium hover:text-teal-700">{{ $absence->employee->name }}</a>
                                    <span class="chip ms-1 {{ $absence->chipClass() }}">{{ $absence->typeLabel() }}</span>
                                    <p class="text-xs text-gray-500">t/m {{ $absence->ends_at->translatedFormat('D j M') }}{{ $absence->ends_at->format('H:i') === '23:59' ? '' : ' '.$absence->ends_at->format('H:i') }}</p>
                                </div>
                            </li>
                        @empty
                            <li class="px-4 py-4 text-sm text-gray-500">Niemand afwezig.</li>
                        @endforelse
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
