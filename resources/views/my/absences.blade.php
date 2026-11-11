<x-app-layout title="Mijn verlof">
    <x-slot name="header">
        <h1 class="page-title">Mijn verlof</h1>
        <a href="{{ route('my.absences.create') }}" class="btn">+ Verlof aanvragen</a>
    </x-slot>

    <div class="max-w-3xl space-y-6">
        <p class="text-sm text-gray-600">Na je aanvraag blijf je voorlopig ingepland. De planner zoekt voor elke dienst een vervanger; zodra dat gelukt is, wordt je verlof automatisch goedgekeurd. Ziek? Meld je direct bij je planner.</p>

        <section class="card">
            <h2 class="border-b px-4 py-2 font-semibold text-gray-800">Nu en gepland</h2>
            <ul class="divide-y text-sm">
                @forelse ($current as $absence)
                    @include('my.partials.absence-row')
                @empty
                    <li class="px-4 py-3 text-gray-500">Geen lopend of gepland verlof.</li>
                @endforelse
            </ul>
        </section>

        <section class="card">
            <h2 class="border-b px-4 py-2 font-semibold text-gray-800">Eerder</h2>
            <ul class="max-h-[32rem] divide-y overflow-y-auto text-sm">
                @forelse ($history as $absence)
                    @include('my.partials.absence-row')
                @empty
                    <li class="px-4 py-3 text-gray-500">Nog geen eerder verlof of afwezigheid.</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-app-layout>
