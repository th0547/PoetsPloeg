<x-app-layout title="Panden">
    <x-slot name="header">
        <h1 class="page-title">Panden <span class="text-base font-normal text-gray-500">({{ $buildings->count() }})</span></h1>
        <a href="{{ route('buildings.create') }}" class="btn"><x-icon name="plus" />Pand</a>
    </x-slot>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($buildings as $building)
            @php
                $shifts = $today->get($building->id, collect());
                $present = $shifts->filter(fn ($s) => $s->starts_at->lte($now) && $s->ends_at->gt($now))->flatMap->assignments->unique('employee_id');
                $next = $shifts->first(fn ($s) => $s->starts_at->gt($now));
                $open = $shifts->sum(fn ($s) => $s->ends_at->gt($now) ? $s->openSlots() : 0);
            @endphp
            <a href="{{ route('buildings.show', $building) }}" class="card block overflow-hidden transition hover:border-teal-300 hover:shadow-md {{ $building->active ? '' : 'opacity-60' }}">
                <div class="flex items-start gap-3 p-4" style="border-top: 4px solid {{ $building->color }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <h2 class="truncate font-semibold text-gray-900">{{ $building->name }}</h2>
                            @unless ($building->active) <span class="badge badge-gray">inactief</span> @endunless
                        </div>
                        <p class="mt-0.5 truncate text-sm text-gray-500">{{ $building->fullAddress() ?: 'Geen adres' }}</p>
                    </div>
                </div>
                <div class="border-t border-gray-100 bg-gray-50/70 px-4 py-2.5 text-sm">
                    @if ($present->isNotEmpty())
                        <p class="flex items-center gap-2 text-gray-800"><span class="dot dot-working"></span>Nu: {{ $present->map(fn ($a) => $a->employee->name)->join(', ', ' en ') }}</p>
                    @elseif ($next)
                        <p class="flex items-center gap-2 text-gray-700"><span class="dot dot-later"></span>Straks {{ $next->starts_at->format('H:i') }}: {{ $next->assignments->map(fn ($a) => $a->employee->name)->join(', ', ' en ') ?: 'nog niemand' }}</p>
                    @elseif ($shifts->isNotEmpty())
                        <p class="flex items-center gap-2 text-gray-500"><span class="dot dot-done"></span>Vandaag klaar</p>
                    @else
                        <p class="flex items-center gap-2 text-gray-400"><span class="dot"></span>Vandaag geen diensten</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-xs text-gray-500">
                    <span>{{ $building->task_templates_count }} vaste {{ $building->task_templates_count === 1 ? 'taak' : 'taken' }}</span>
                    <span>{{ $building->employees_count }} ingewerkt</span>
                    @if ($open)<span class="badge badge-orange ms-auto">{{ $open }} open vandaag</span>@endif
                </div>
            </a>
        @empty
            <div class="card empty sm:col-span-2 xl:col-span-3">
                <x-icon name="building" class="icon-lg" />
                <p>Nog geen panden. <a href="{{ route('buildings.create') }}" class="link">Voeg het eerste pand toe</a>.</p>
            </div>
        @endforelse
    </div>
</x-app-layout>
