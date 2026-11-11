@php
    $todo = $rows->where('state', 'todo')->count();
    $open = $rows->where('state', 'open')->count();
    $done = $rows->where('state', 'done')->count();
@endphp
<x-app-layout title="Verlofverzoek">
    <x-slot name="header">
        <div>
            <h1 class="page-title">
                {{ $absence->employee->name }} · {{ $absence->status === 'approved' ? $absence->typeLabel() : $absence->requestLabel() }}
                <span class="chip ms-1 align-middle {{ ['pending' => 'bg-amber-100 text-amber-800', 'approved' => 'bg-teal-100 text-teal-800', 'rejected' => 'bg-red-100 text-red-800'][$absence->status] }}">{{ $absence->statusLabel() }}</span>
            </h1>
            <p class="text-sm text-gray-600">{{ $absence->starts_at->translatedFormat('l j F Y H:i') }} t/m {{ $absence->ends_at->translatedFormat('l j F Y H:i') }}</p>
            @if ($absence->note)<p class="text-sm text-gray-500">{{ $absence->note }}</p>@endif
        </div>
        <a href="{{ route('absences.index') }}" class="btn-secondary">‹ Alle afwezigheid</a>
    </x-slot>

    <div x-data @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()" class="max-w-4xl space-y-6">
        @if ($absence->isPending())
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <p class="font-medium">Dit verlof wordt pas goedgekeurd als er voor alle diensten een vervanger is.</p>
                <p class="mt-1">
                    Nog te vervangen: <strong>{{ $todo + $open }}</strong> van {{ $rows->count() }} {{ $rows->count() === 1 ? 'dienst' : 'diensten' }}.
                    Kies per dienst “Zoek vervanger”; de vervanger neemt de plek van {{ $absence->employee->name }} in één keer over.
                </p>
            </div>
        @elseif ($absence->status === 'approved')
            <div class="rounded-md border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900">
                Goedgekeurd{{ $absence->decided_at ? ' op '.$absence->decided_at->translatedFormat('j F H:i') : '' }}: alle diensten zijn vervangen.
            </div>
        @else
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                Afgewezen{{ $absence->decided_at ? ' op '.$absence->decided_at->translatedFormat('j F H:i') : '' }}. {{ $absence->employee->name }} blijft ingepland op de diensten die nog niet vervangen waren.
            </div>
        @endif

        <section class="card">
            <div class="flex items-center justify-between border-b px-4 py-3">
                <h2 class="font-semibold text-gray-800">Diensten in deze periode ({{ $rows->count() }})</h2>
                <span class="text-xs text-gray-500">{{ $done }} vervangen</span>
            </div>
            <ul class="divide-y">
                @forelse ($rows as $row)
                    @php($shift = $row['shift'])
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5" style="border-left: 4px solid {{ $shift->building->color }}">
                        <div class="min-w-0">
                            <p class="text-sm">
                                <span class="text-gray-600">{{ $shift->starts_at->translatedFormat('D j M') }} {{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</span>
                                <span class="ms-2 font-medium">{{ $shift->building->name }}</span>
                                <span class="text-gray-600">· {{ $shift->title }}</span>
                            </p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($shift->assignments as $a)
                                    <span class="chip {{ $a->employee_id === $absence->employee_id ? 'bg-amber-100 text-amber-900 line-through decoration-amber-500' : '' }}">{{ $a->employee->name }}</span>
                                @endforeach
                                @if ($row['state'] === 'done')
                                    <span class="chip bg-teal-100 text-teal-800">{{ $shift->cancelled_at ? 'Geannuleerd' : '✓ Vervangen' }}</span>
                                @elseif ($row['state'] === 'open')
                                    <span class="chip bg-orange-100 text-orange-800">{{ $shift->openSlots() }} open</span>
                                @endif
                            </div>
                        </div>
                        @if ($row['state'] === 'todo' && $absence->isPending())
                            <button type="button" class="btn btn-sm shrink-0" onclick="openShift({{ $shift->id }}, true, {{ $absence->employee_id }})">Zoek vervanger</button>
                        @elseif ($row['state'] === 'open' && ! $shift->cancelled_at)
                            <button type="button" class="btn btn-sm shrink-0" onclick="openShift({{ $shift->id }}, true)">Plek invullen</button>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-4 text-sm text-gray-500">Geen diensten in deze periode.</li>
                @endforelse
            </ul>
        </section>

        <div class="flex flex-wrap gap-3">
            @if ($absence->isPending())
                <form method="POST" action="{{ route('absences.reject', $absence) }}" onsubmit="return confirm('Verlofverzoek afwijzen?')">
                    @csrf
                    <button type="submit" class="btn-danger">Verzoek afwijzen</button>
                </form>
            @endif
            <form method="POST" action="{{ route('absences.destroy', $absence) }}" onsubmit="return confirm('Intrekken? Al ingeplande vervangers blijven staan. De registratie blijft bewaard in de geschiedenis.')">
                @csrf @method('DELETE')
                <button type="submit" class="btn-secondary">Intrekken</button>
            </form>
        </div>
    </div>
</x-app-layout>
