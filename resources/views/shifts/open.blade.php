<x-app-layout title="Open diensten">
    <x-slot name="header">
        <div>
            <h1 class="page-title">Open diensten</h1>
            <p class="text-sm text-gray-500">
                @if ($shiftCount)
                    {{ $shiftCount }} {{ $shiftCount === 1 ? 'dienst' : 'diensten' }} met samen {{ $slotCount }} open {{ $slotCount === 1 ? 'plek' : 'plekken' }}
                @else
                    Geen open plekken
                @endif
                in de komende {{ \App\Http\Controllers\OpenShiftController::PERIODS[$weeks] }}.
            </p>
        </div>
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="weken" onchange="this.form.submit()" class="rounded-md border-gray-300 text-sm shadow-sm" aria-label="Periode">
                @foreach (\App\Http\Controllers\OpenShiftController::PERIODS as $value => $label)
                    <option value="{{ $value }}" @selected($weeks === $value)>Komende {{ $label }}</option>
                @endforeach
            </select>
            <select name="pand" onchange="this.form.submit()" class="rounded-md border-gray-300 text-sm shadow-sm" aria-label="Pand">
                <option value="">Alle panden</option>
                @foreach ($buildings as $building)
                    <option value="{{ $building->id }}" @selected($buildingId === $building->id)>{{ $building->name }}</option>
                @endforeach
            </select>
        </form>
    </x-slot>

    <div x-data @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()" class="space-y-4">
        @forelse ($days as $date => $shifts)
            @php($day = \Carbon\CarbonImmutable::parse($date))
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b bg-gray-50 px-4 py-2">
                    <h2 class="font-semibold text-gray-800">
                        {{ ucfirst($day->translatedFormat('l j F')) }}
                        @if ($day->isToday())<span class="chip ms-1 bg-teal-100 text-teal-800">vandaag</span>
                        @elseif ($day->isTomorrow())<span class="chip ms-1">morgen</span>@endif
                    </h2>
                    <span class="text-xs text-orange-700">{{ $shifts->sum(fn ($s) => $s->openSlots()) }} open</span>
                </div>
                <ul class="divide-y">
                    @foreach ($shifts as $shift)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5" style="border-left: 4px solid {{ $shift->building->color }}">
                            <div class="min-w-0">
                                <p class="text-sm">
                                    <span class="font-mono text-gray-600">{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</span>
                                    <span class="ms-2 font-medium text-gray-900">{{ $shift->building->name }}</span>
                                    <span class="text-gray-600">· {{ $shift->title }}</span>
                                </p>
                                <div class="mt-1 flex flex-wrap items-center gap-1">
                                    @foreach ($shift->assignments as $a)
                                        <span class="chip"><span class="me-1 h-2 w-2 rounded-full" style="background: {{ $a->employee->color }}"></span>{{ $a->employee->name }}</span>
                                    @endforeach
                                    <span class="chip bg-orange-100 text-orange-800">{{ $shift->openSlots() }} van {{ $shift->required_staff }} open</span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm shrink-0" onclick="openShift({{ $shift->id }}, true)">Zoek vervanger</button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="card card-body text-center text-sm text-teal-700">Alles is bezet in deze periode. 👍</div>
        @endforelse
    </div>
</x-app-layout>
