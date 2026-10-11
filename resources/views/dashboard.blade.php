<x-app-layout title="Vandaag">
    <x-slot name="header">
        <div>
            <h1 class="page-title">
                {{ $day->isToday() ? 'Vandaag' : 'Dagoverzicht' }}
                <span class="font-normal text-gray-500">· {{ $day->translatedFormat('l j F Y') }}</span>
            </h1>
            @if ($holiday)
                <p class="text-sm text-amber-700">🎉 {{ $holiday }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <a class="btn-secondary" href="{{ route('dashboard', ['datum' => $day->subDay()->toDateString()]) }}" aria-label="Vorige dag">‹</a>
            <a class="btn-secondary" href="{{ route('dashboard') }}">Vandaag</a>
            <a class="btn-secondary" href="{{ route('dashboard', ['datum' => $day->addDay()->toDateString()]) }}" aria-label="Volgende dag">›</a>
            <form method="GET" action="{{ route('dashboard') }}">
                <input type="date" name="datum" value="{{ $day->toDateString() }}" onchange="this.form.submit()" class="rounded-md border-gray-300 text-sm shadow-sm">
            </form>
        </div>
    </x-slot>

    <div x-data @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="card card-body">
                <p class="text-xs uppercase tracking-wide text-gray-500">Diensten deze dag</p>
                <p class="mt-1 text-2xl font-semibold">{{ $shiftCount }}</p>
            </div>
            <div class="card card-body">
                <p class="text-xs uppercase tracking-wide text-gray-500">Open plekken (14 dagen)</p>
                <p class="mt-1 text-2xl font-semibold {{ $openSlotCount ? 'text-orange-600' : 'text-teal-700' }}">{{ $openSlotCount }}</p>
            </div>
            <div class="card card-body">
                <p class="text-xs uppercase tracking-wide text-gray-500">Afwezig</p>
                <p class="mt-1 text-2xl font-semibold">{{ $absentToday->count() }}</p>
            </div>
            <div class="card card-body">
                <p class="text-xs uppercase tracking-wide text-gray-500">Waarschuwingen deze dag</p>
                <p class="mt-1 text-2xl font-semibold {{ $warningCount ? 'text-amber-600' : 'text-teal-700' }}">{{ $warningCount }}</p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <section class="lg:col-span-2 space-y-4">
                <h2 class="text-base font-semibold text-gray-800">Diensten per pand</h2>
                @forelse ($shiftsByBuilding as $buildingName => $shifts)
                    <div class="card overflow-hidden">
                        <div class="flex items-center justify-between border-b px-4 py-2" style="border-left: 4px solid {{ $shifts->first()->building->color }}">
                            <a href="{{ route('buildings.show', $shifts->first()->building) }}" class="font-medium text-gray-800 hover:text-teal-700">{{ $buildingName }}</a>
                            <span class="text-xs text-gray-500">{{ $shifts->first()->building->fullAddress() }}</span>
                        </div>
                        <ul class="divide-y">
                            @foreach ($shifts as $shift)
                                <li class="px-4 py-2 hover:bg-gray-50 cursor-pointer" onclick="openShift({{ $shift->id }})">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <span class="font-mono text-sm text-gray-600">{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</span>
                                            <span class="ms-2 font-medium">{{ $shift->title }}</span>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-1">
                                            @foreach ($shift->assignments as $a)
                                                @php($w = $warnings[$shift->id][$a->employee_id] ?? [])
                                                @php($level = \App\Support\Planning::worstLevel($w))
                                                <span title="{{ collect($w)->pluck('message')->implode("\n") }}"
                                                      class="chip {{ $level === 'danger' ? 'bg-red-100 text-red-800' : ($level === 'warning' ? 'bg-amber-100 text-amber-800' : '') }}">
                                                    <span class="me-1 h-2 w-2 rounded-full" style="background: {{ $a->employee->color }}"></span>{{ $a->employee->name }}
                                                    @if ($level === 'danger' || $level === 'warning') ⚠ @endif
                                                </span>
                                            @endforeach
                                            @if ($shift->openSlots() > 0)
                                                <span class="chip bg-orange-100 text-orange-800">{{ $shift->openSlots() }} open</span>
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <div class="card card-body text-sm text-gray-500">Geen diensten op deze dag.</div>
                @endforelse
            </section>

            <aside class="space-y-6">
                <section>
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold text-gray-800">Open diensten</h2>
                        <span class="text-xs text-gray-500">komende 14 dagen</span>
                    </div>
                    <div class="card mt-2 overflow-hidden">
                        <ul class="divide-y">
                            @forelse ($openShifts->take(25) as $shift)
                                <li class="px-4 py-2 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="font-medium truncate">{{ $shift->building->name }} · {{ $shift->title }}</p>
                                            <p class="text-xs text-gray-500">
                                                {{ $shift->starts_at->translatedFormat('D j M') }}, {{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}
                                                · <span class="text-orange-700">{{ $shift->required_staff - $shift->assignments_count }} open</span>
                                            </p>
                                        </div>
                                        <button type="button" class="btn btn-sm shrink-0" onclick="openShift({{ $shift->id }}, true)">Zoek vervanger</button>
                                    </div>
                                </li>
                            @empty
                                <li class="px-4 py-3 text-sm text-teal-700">Alles is bezet. 👍</li>
                            @endforelse
                        </ul>
                        @if ($openShifts->count() > 25)
                            <p class="border-t px-4 py-2 text-xs text-gray-500">En nog {{ $openShifts->count() - 25 }} meer — zie de planning.</p>
                        @endif
                    </div>
                </section>

                <section>
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold text-gray-800">Afwezig</h2>
                        <a href="{{ route('absences.create') }}" class="text-sm text-teal-700 hover:underline">+ Uitval melden</a>
                    </div>
                    <div class="card mt-2 overflow-hidden">
                        <ul class="divide-y">
                            @forelse ($absentToday as $absence)
                                <li class="px-4 py-2 text-sm">
                                    <a href="{{ route('absences.show', $absence) }}" class="font-medium hover:text-teal-700">{{ $absence->employee->name }}</a>
                                    <span class="chip ms-1 {{ $absence->type === 'ziek' ? 'bg-red-100 text-red-800' : '' }}">{{ $absence->typeLabel() }}</span>
                                    <p class="text-xs text-gray-500">t/m {{ $absence->ends_at->translatedFormat('D j M H:i') }}</p>
                                </li>
                            @empty
                                <li class="px-4 py-3 text-sm text-gray-500">Niemand afwezig.</li>
                            @endforelse
                        </ul>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
