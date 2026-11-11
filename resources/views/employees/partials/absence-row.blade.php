{{-- Eén regel in "Verlof & afwezigheid" op de medewerkerpagina. --}}
<li class="py-2">
    <a href="{{ route('absences.show', $absence) }}" class="block hover:text-teal-700">
        <span class="chip {{ $absence->chipClass() }}">{{ $absence->typeLabel() }}</span>
        @if ($absence->status !== 'approved')
            <span class="chip {{ $absence->isPending() ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800' }}">{{ $absence->isPending() ? 'in afwachting' : 'afgewezen' }}</span>
        @endif
        <span class="mt-0.5 block">
            @if ($absence->starts_at->isSameDay($absence->ends_at))
                {{ $absence->starts_at->translatedFormat('D j M Y') }}@if ($absence->starts_at->format('H:i') !== '00:00' || $absence->ends_at->format('H:i') !== '23:59'), {{ $absence->starts_at->format('H:i') }}–{{ $absence->ends_at->format('H:i') }}@endif
            @else
                {{ $absence->starts_at->translatedFormat('D j M Y') }}@if ($absence->starts_at->format('H:i') !== '00:00') {{ $absence->starts_at->format('H:i') }}@endif
                t/m {{ $absence->ends_at->translatedFormat('D j M Y') }}@if ($absence->ends_at->format('H:i') !== '23:59') {{ $absence->ends_at->format('H:i') }}@endif
            @endif
        </span>
    </a>
    @if ($absence->note)<p class="text-xs text-gray-500">{{ $absence->note }}</p>@endif
</li>
