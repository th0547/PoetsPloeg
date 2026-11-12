{{-- Eén regel in "Mijn verlof". --}}
<li class="flex flex-wrap items-start justify-between gap-2 px-4 py-2">
    <div>
        <span class="chip {{ $absence->chipClass() }}">{{ $absence->typeLabel() }}</span>
        @if ($absence->hours > 0)<span class="text-xs text-gray-500">{{ $absence->hoursLabel() }}</span>@endif
        @if ($absence->trashed())
            <span class="chip bg-gray-200 text-gray-700">Ingetrokken</span>
        @else
            <span class="chip {{ match ($absence->status) { 'pending' => 'bg-amber-100 text-amber-800', 'rejected' => 'bg-red-100 text-red-800', default => 'bg-teal-50 text-teal-800' } }}">{{ $absence->statusLabel() }}</span>
        @endif
        <span class="mt-0.5 block">{{ $absence->periodLabel() }}</span>
        @if ($absence->note)<span class="block text-xs text-gray-500">{{ $absence->note }}</span>@endif
    </div>
    @if ($absence->isPending() && ! $absence->trashed())
        <form method="POST" action="{{ route('my.absences.destroy', $absence) }}" data-confirm="Aanvraag intrekken? Je blijft dan gewoon ingepland. Is er al een vervanger gevonden voor een dienst, overleg dan met je planner.">
            @csrf @method('DELETE')
            <button type="submit" class="text-sm text-red-600 hover:underline">Intrekken</button>
        </form>
    @endif
</li>
