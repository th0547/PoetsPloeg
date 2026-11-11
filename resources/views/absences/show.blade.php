<x-app-layout title="Afwezigheid">
    <x-slot name="header">
        <div>
            <h1 class="page-title">{{ $absence->employee->name }} · <span class="chip align-middle text-sm {{ $absence->chipClass() }}">{{ $absence->typeLabel() }}</span></h1>
            <p class="text-sm text-gray-600">{{ $absence->starts_at->translatedFormat('l j F Y H:i') }} t/m {{ $absence->ends_at->translatedFormat('l j F Y H:i') }}</p>
            @if ($absence->note)<p class="text-sm text-gray-500">{{ $absence->note }}</p>@endif
        </div>
        <a href="{{ route('absences.index') }}" class="btn-secondary">‹ Alle afwezigheid</a>
    </x-slot>

    <div x-data @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()" class="max-w-4xl space-y-6">
        <section class="card">
            <div class="border-b px-4 py-3">
                <h2 class="font-semibold text-gray-800">Open diensten in deze periode ({{ $openShifts->count() }})</h2>
                <p class="text-xs text-gray-500">Klik op “Zoek vervanger” voor een gerangschikte lijst van geschikte collega’s.</p>
            </div>
            <ul class="divide-y">
                @forelse ($openShifts as $shift)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                        <div>
                            <p class="font-medium">{{ $shift->building->name }} · {{ $shift->title }}</p>
                            <p class="text-xs text-gray-500">{{ $shift->starts_at->translatedFormat('D j M') }}, {{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}
                                · <span class="text-orange-700">{{ $shift->required_staff - $shift->assignments_count }} open</span></p>
                        </div>
                        <button type="button" class="btn btn-sm" data-open-shift="{{ $shift->id }}" data-candidates>Zoek vervanger</button>
                    </li>
                @empty
                    <li class="px-4 py-4 text-sm text-teal-700">Geen open diensten in deze periode. 👍</li>
                @endforelse
            </ul>
        </section>

        <form method="POST" action="{{ route('absences.destroy', $absence) }}" data-confirm="Deze afwezigheid intrekken? Eerder vrijgemaakte diensten worden niet automatisch teruggezet. De registratie blijft bewaard in de geschiedenis.">
            @csrf @method('DELETE')
            <button type="submit" class="btn-danger">Afwezigheid intrekken</button>
        </form>
    </div>
</x-app-layout>
