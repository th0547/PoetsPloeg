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
            <select name="weken" data-auto-submit class="input mt-0 !w-auto" aria-label="Periode">
                @foreach (\App\Http\Controllers\OpenShiftController::PERIODS as $value => $label)
                    <option value="{{ $value }}" @selected($weeks === $value)>Komende {{ $label }}</option>
                @endforeach
            </select>
            <select name="pand" data-auto-submit class="input mt-0 !w-auto" aria-label="Pand">
                <option value="">Alle panden</option>
                @foreach ($buildings as $building)
                    <option value="{{ $building->id }}" @selected($buildingId === $building->id)>{{ $building->name }}</option>
                @endforeach
            </select>
        </form>
    </x-slot>

    <div x-data @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()" class="max-w-5xl space-y-5">
        @if ($shiftCount)
            <p class="text-sm text-gray-600">Bij elke dienst staan de beste kandidaten al klaar: één klik op <strong>Inplannen</strong> en de plek is gevuld.</p>
        @endif
        @forelse ($days as $date => $shifts)
            @php($day = \Carbon\CarbonImmutable::parse($date))
            <section class="card overflow-hidden">
                <div class="card-head bg-gray-50">
                    <h2 class="card-title">
                        {{ ucfirst($day->translatedFormat('l j F')) }}
                        @if ($day->isToday())<span class="badge badge-teal ms-1">vandaag</span>
                        @elseif ($day->isTomorrow())<span class="badge badge-gray ms-1">morgen</span>@endif
                    </h2>
                    <span class="badge badge-orange">{{ $shifts->sum(fn ($s) => $s->openSlots()) }} open</span>
                </div>
                <ul class="divide-y divide-gray-200">
                    @foreach ($shifts as $shift)
                        @include('partials.replace-card', ['shift' => $shift, 'replace' => null, 'showDate' => false, 'missing' => $missing[$shift->id] ?? []])
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="card empty !py-12">
                <x-icon name="check-circle" class="icon-lg text-teal-600" />
                <p class="font-medium text-gray-800">Alles is bezet in deze periode.</p>
                <p>Er staan geen open plekken{{ $buildingId ? ' voor dit pand' : '' }}.</p>
            </div>
        @endforelse
    </div>
</x-app-layout>
