<x-app-layout title="Panden">
    <x-slot name="header">
        <h1 class="page-title">Panden <span class="text-base font-normal text-gray-500">({{ $buildings->count() }})</span></h1>
        <a href="{{ route('buildings.create') }}" class="btn">+ Pand</a>
    </x-slot>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($buildings as $building)
            <a href="{{ route('buildings.show', $building) }}" class="card card-body block hover:ring-2 hover:ring-teal-200 {{ $building->active ? '' : 'opacity-60' }}" style="border-top: 4px solid {{ $building->color }}">
                <div class="flex items-start justify-between gap-2">
                    <h2 class="font-semibold text-gray-900">{{ $building->name }}</h2>
                    @unless ($building->active) <span class="chip">inactief</span> @endunless
                </div>
                <p class="mt-1 text-sm text-gray-600">{{ $building->fullAddress() ?: 'Geen adres' }}</p>
                <div class="mt-3 flex gap-3 text-xs text-gray-500">
                    <span>{{ $building->task_templates_count }} vaste {{ $building->task_templates_count === 1 ? 'taak' : 'taken' }}</span>
                    <span>{{ $building->employees_count }} ingewerkt</span>
                </div>
            </a>
        @empty
            <div class="card card-body text-sm text-gray-500 sm:col-span-2 lg:col-span-3">
                Nog geen panden. <a href="{{ route('buildings.create') }}" class="text-teal-700 hover:underline">Voeg het eerste pand toe</a>.
            </div>
        @endforelse
    </div>
</x-app-layout>
