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

        <section class="card card-body space-y-2">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-gray-800">Mijn tellers</h2>
                <span class="flex items-center gap-1 text-sm">
                    <a href="{{ route('my.absences', ['jaar' => $year - 1]) }}" class="text-teal-700 hover:underline" aria-label="Vorig jaar">‹</a>
                    {{ $year }}
                    <a href="{{ route('my.absences', ['jaar' => $year + 1]) }}" class="text-teal-700 hover:underline" aria-label="Volgend jaar">›</a>
                </span>
            </div>
            @include('partials.leave-counters', ['counters' => $counters, 'balance' => $balance, 'year' => $year, 'showBalance' => $showBalance])
        </section>

        <section class="card">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2">
                <h2 class="font-semibold text-gray-800">Eerder</h2>
                <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
                    <label for="status" class="sr-only">Status</label>
                    <select id="status" name="status" class="rounded-md border-gray-300 py-1 text-sm" data-auto-submit>
                        @foreach (\App\Http\Controllers\My\MyController::HISTORY_FILTERS as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <label class="inline-flex items-center gap-1"><input type="checkbox" name="jaar" value="{{ $year }}" class="checkbox" @checked($yearFiltered) data-auto-submit> alleen {{ $year }}</label>
                </form>
            </div>
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
